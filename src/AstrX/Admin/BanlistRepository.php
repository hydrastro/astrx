<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Config\Config;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;
use AstrX\Result\DiagnosticLevel;

/**
 * Banlist data-access.
 *
 * Route/round definitions (penalty schedules) are stored in Banlist.config.php
 * and edited via the admin config UI. They are compile-time configuration, not
 * runtime data — keeping them in PHP gives full git history and zero DB coupling.
 *
 * Active ban records (who is banned, until when) are stored in the DB:
 *   banlist          — one row per ban (route name, reason, start/end, active flag)
 *   banlist_user     — FK → user
 *   banlist_email    — email address bans
 *   banlist_ip       — CIDR bans
 *
 * ban_route is now a VARCHAR(64) route key (e.g. 'permanent', 'bad_comment')
 * that matches the keys in Banlist.config.php → BanlistRepository.routes.
 * This removes the FK dependency on the now-dropped banlist_route/banlist_round
 * tables and makes ban records self-descriptive.
 *
 * Route key constants are defined here as the single source of truth.
 */
final class BanlistRepository
{
    // ---- Route key constants ------------------------------------------------
    // These match the array keys in Banlist.config.php.
    // Add new constants here when adding new routes to the config.

    public const string ROUTE_PERMANENT    = 'permanent';
    public const string ROUTE_BAD_COMMENT  = 'bad_comment';
    public const string ROUTE_FAILED_LOGIN = 'failed_login';

    // -------------------------------------------------------------------------

    /**
     * Route configuration loaded from Banlist.config.php.
     * Shape: array<string, list<array{penalty:int, max_tries:int, check_time:int, enabled:bool}>>
     * @var array<string, list<array<string, mixed>>>
     */
    private array $routes = [];

    public function __construct(
        private readonly PDO    $pdo,
    ) {}

    #[InjectConfig('routes')]
    public function setRoutes(array $routes): void
    {
        $this->routes = $routes;
    }

    // =========================================================================
    // Routes & rounds — read from PHP config
    // =========================================================================

    /**
     * All route definitions from config, each with their rounds.
     * Shape mirrors what the old DB version returned, so callers don't change.
     *
     * @return list<array{key:string, name:string, rounds:list<array<string,mixed>>}>
     */
    public function listRoutes(): array
    {
        $result = [];
        foreach ($this->routes as $key => $rounds) {
            $result[] = [
                'key'    => $key,
                'name'   => $key,   // display name = key; can be i18n'd in template
                'rounds' => array_values($rounds),
            ];
        }
        return $result;
    }

    /**
     * Look up a single route's round schedule by key.
     *
     * @return list<array{penalty:int,max_tries:int,check_time:int,enabled:bool}>|null
     */
    public function routeRounds(string $routeKey): ?array
    {
        if (!isset($this->routes[$routeKey])) {
            return null;
        }
        return array_values($this->routes[$routeKey]);
    }

    // =========================================================================
    // Bans — listing & lookup
    // =========================================================================

    /** @return Result<list<array<string,mixed>>> */
    public function listAll(): Result
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT b.id, b.ban_route,
                        b.reason, b.start, b.end, b.active,
                        'user'  AS type,
                        LOWER(HEX(bu.user_id)) AS value
                   FROM banlist b
                   JOIN banlist_user bu ON bu.ban_id = b.id
                 UNION ALL
                 SELECT b.id, b.ban_route,
                        b.reason, b.start, b.end, b.active,
                        'email', be.email
                   FROM banlist b
                   JOIN banlist_email be ON be.ban_id = b.id
                 UNION ALL
                 SELECT b.id, b.ban_route,
                        b.reason, b.start, b.end, b.active,
                        'ip',
                        CONCAT(INET6_NTOA(bi.network), '/', bi.prefix_len)
                   FROM banlist b
                   JOIN banlist_ip bi ON bi.ban_id = b.id
                 ORDER BY id DESC"
            );
            return Result::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function findById(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id, b.ban_route, b.reason, b.start, b.end, b.active,
                        bu.user_id,
                        be.email,
                        CONCAT(INET6_NTOA(bi.network),\'/\',bi.prefix_len) AS cidr
                   FROM banlist b
                   LEFT JOIN banlist_user  bu ON bu.ban_id = b.id
                   LEFT JOIN banlist_email be ON be.ban_id = b.id
                   LEFT JOIN banlist_ip    bi ON bi.ban_id = b.id
                  WHERE b.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return Result::ok($row !== false ? $row : null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int|null> */
    public function findActiveBanForIp(string $ip): Result
    {
        $parsed = self::parseCidr($ip);
        if ($parsed === null) { return Result::ok(null); }
        $packedIp = $parsed['network'];

        try {
            $rows = $this->pdo->query(
                'SELECT b.id, bi.network, bi.prefix_len
                   FROM banlist b JOIN banlist_ip bi ON bi.ban_id = b.id
                  WHERE b.active = 1'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (self::ipMatchesCidr($packedIp, (string) $row['network'], (int) $row['prefix_len'])) {
                    return Result::ok((int) $row['id']);
                }
            }
            return Result::ok(null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int|null> */
    public function findActiveBanForEmail(string $email): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id FROM banlist b
                   JOIN banlist_email be ON be.ban_id = b.id
                  WHERE b.active = 1 AND LOWER(be.email) = LOWER(:email) LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return Result::ok($row !== false ? (int) $row['id'] : null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // =========================================================================
    // Bans — write
    // =========================================================================

    /** @return Result<int> */
    public function banCidr(string $cidr, string $reason, string $route, ?string $end = null): Result
    {
        $parsed = self::parseCidr($cidr);
        if ($parsed === null) {
            return Result::err(null, Diagnostics::of(new AdminDbDiagnostic(
                                                         'astrx.admin/db_error', DiagnosticLevel::ERROR, "Invalid IP/CIDR: {$cidr}"
                                                     )));
        }
        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) { return $coreResult; }
        $banId = $coreResult->unwrap();
        try {
            $this->pdo->prepare(
                'INSERT INTO banlist_ip (ban_id, network, prefix_len) VALUES (:id, :net, :prefix)'
            )->execute([':id' => $banId, ':net' => $parsed['network'], ':prefix' => $parsed['prefix']]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> */
    public function banEmail(string $email, string $reason, string $route, ?string $end = null): Result
    {
        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) { return $coreResult; }
        $banId = $coreResult->unwrap();
        try {
            $this->pdo->prepare(
                'INSERT INTO banlist_email (ban_id, email) VALUES (:id, :email)'
            )->execute([':id' => $banId, ':email' => $email]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> */
    public function banUser(string $hexUserId, string $reason, string $route, ?string $end = null): Result
    {
        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) { return $coreResult; }
        $banId = $coreResult->unwrap();
        try {
            $this->pdo->prepare(
                'INSERT INTO banlist_user (ban_id, user_id) VALUES (:id, UNHEX(:uid))'
            )->execute([':id' => $banId, ':uid' => $hexUserId]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function updateBan(int $id, string $reason, string $route, ?string $end, bool $active): Result
    {
        try {
            $this->pdo->prepare(
                'UPDATE banlist SET reason = :reason, ban_route = :route, end = :end, active = :active
                  WHERE id = :id'
            )->execute([':reason' => $reason, ':route' => $route,
                        ':end' => $end, ':active' => (int) $active, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function setActive(int $id, bool $active): Result
    {
        try {
            $this->pdo->prepare('UPDATE banlist SET active = :a WHERE id = :id')
                ->execute([':a' => (int) $active, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function delete(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM banlist WHERE id = :id')
                ->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // =========================================================================
    // CIDR helpers
    // =========================================================================

    /** @return array{network:string,prefix:int}|null */
    public static function parseCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if (str_contains($cidr, '/')) {
            [$addr, $prefixStr] = explode('/', $cidr, 2);
            $prefix = (int) $prefixStr;
        } else {
            $addr   = $cidr;
            $prefix = str_contains($cidr, ':') ? 128 : 32;
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $packed = inet_pton('::ffff:' . $addr);
            $prefix += 96;
        } elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($addr);
        } else {
            return null;
        }
        if ($packed === false || $prefix < 0 || $prefix > 128) { return null; }
        return ['network' => self::applyMask($packed, $prefix), 'prefix' => $prefix];
    }

    private static function applyMask(string $packed, int $prefix): string
    {
        $result = str_repeat("\x00", 16);
        for ($i = 0; $i < 16; $i++) {
            $bits      = max(0, min(8, $prefix - $i * 8));
            $mask      = $bits === 0 ? 0x00 : (0xFF & (0xFF << (8 - $bits)));
            $result[$i] = chr(ord($packed[$i]) & $mask);
        }
        return $result;
    }

    public static function ipMatchesCidr(string $packedIp, string $packedNetwork, int $prefix): bool
    {
        return self::applyMask($packedIp, $prefix) === $packedNetwork;
    }

    // =========================================================================

    private function insertCore(string $reason, string $route, ?string $end): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO banlist (ban_route, reason, end) VALUES (:route, :reason, :end)'
            )->execute([':route' => $route, ':reason' => $reason, ':end' => $end]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new AdminDbDiagnostic(
                                                     'astrx.admin/db_error', DiagnosticLevel::ERROR, $e->getMessage()
                                                 )));
    }
}
