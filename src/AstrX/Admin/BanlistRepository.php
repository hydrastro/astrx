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
    public const string ROUTE_CHAT         = 'chat';

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

    /**
     * @param array<string, list<array<string,mixed>>> $routes
     */
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
                'rounds' => $rounds,
            ];
        }
        return $result;
    }

    /**
     * Look up a single route's round schedule by key.
     *
     * @return list<array<string,mixed>>|null
     */
    public function routeRounds(string $routeKey): ?array
    {
        if (!isset($this->routes[$routeKey])) {
            return null;
        }
        return $this->routes[$routeKey];
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
                 UNION ALL
                 SELECT b.id, b.ban_route,
                        b.reason, b.start, b.end, b.active,
                        'nick', bn.nick
                   FROM banlist b
                   JOIN banlist_nick bn ON bn.ban_id = b.id
                 ORDER BY id DESC"
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
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
                        bn.nick,
                        CONCAT(INET6_NTOA(bi.network),\'/\',bi.prefix_len) AS cidr
                   FROM banlist b
                   LEFT JOIN banlist_user  bu ON bu.ban_id = b.id
                   LEFT JOIN banlist_email be ON be.ban_id = b.id
                   LEFT JOIN banlist_nick  bn ON bn.ban_id = b.id
                   LEFT JOIN banlist_ip    bi ON bi.ban_id = b.id
                  WHERE b.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            return Result::ok($fetched);
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
            $banStmt = $this->pdo->query(
                'SELECT b.id, bi.network, bi.prefix_len
                   FROM banlist b JOIN banlist_ip bi ON bi.ban_id = b.id
                  WHERE b.active = 1 AND (b.end IS NULL OR b.end > NOW())'
            );
            assert($banStmt !== false);
            $rows = $banStmt->fetchAll(PDO::FETCH_ASSOC);
            /** @var list<array<string,mixed>> $rows */
            foreach ($rows as $row) {
                // R11 (LOW): fail CLOSED. is_int() on a fetched integer is false
                // under non-default fetch modes (STRINGIFY_FETCHES, non-mysqlnd,
                // BIGINT>PHP_INT_MAX), and defaulting prefix_len to 0 makes
                // applyMask() zero every byte → the ban NEVER matches, silently
                // un-enforcing the IP ban (fails OPEN). Use is_numeric with a /128
                // (exact-match) fallback, mirroring Imageboard\BanRepository.
                $prefixLen = is_int($row['prefix_len']) ? $row['prefix_len'] : (is_numeric($row['prefix_len']) ? (int)$row['prefix_len'] : 128);
                if (self::ipMatchesCidr($packedIp, is_scalar($row['network']) ? (string)$row['network'] : '', $prefixLen)) {
                    return Result::ok(is_int($row['id']) ? $row['id'] : (is_numeric($row['id']) ? (int)$row['id'] : 0));
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
                  WHERE b.active = 1 AND (b.end IS NULL OR b.end > NOW()) AND LOWER(be.email) = LOWER(:email) LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            $idV = $fetched['id'];
            return Result::ok(is_int($idV) ? $idV : (is_numeric($idV) ? (int)$idV : 0));
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

    /** @return Result<int> */
    public function banNick(string $nick, string $reason, string $route, ?string $end = null): Result
    {
        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) { return $coreResult; }
        $banId = $coreResult->unwrap();
        try {
            $this->pdo->prepare(
                'INSERT INTO banlist_nick (ban_id, nick) VALUES (:id, :nick)'
            )->execute([':id' => $banId, ':nick' => $nick]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int|null> */
    public function findActiveBanForNick(string $nick): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id FROM banlist b
                   JOIN banlist_nick bn ON bn.ban_id = b.id
                  WHERE b.active = 1 AND (b.end IS NULL OR b.end > NOW()) AND LOWER(bn.nick) = LOWER(:nick) LIMIT 1'
            );
            $stmt->execute([':nick' => $nick]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            $idV = $fetched['id'];
            return Result::ok(is_int($idV) ? $idV : (is_numeric($idV) ? (int) $idV : 0));
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int|null> */
    public function findActiveBanForUser(string $hexUserId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id FROM banlist b
                   JOIN banlist_user bu ON bu.ban_id = b.id
                  WHERE b.active = 1 AND (b.end IS NULL OR b.end > NOW()) AND bu.user_id = UNHEX(:uid) LIMIT 1'
            );
            $stmt->execute([':uid' => $hexUserId]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            $idV = $fetched['id'];
            return Result::ok(is_int($idV) ? $idV : (is_numeric($idV) ? (int) $idV : 0));
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
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

    /** @return Result<bool> */
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

    /** @return Result<bool> */
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
            // Reject empty, negative or non-numeric prefixes BEFORE the IPv4 +96
            // offset. ctype_digit('') and ctype_digit('-10') are both false, so
            // "1.2.3.4/" and "1.2.3.4/-10" no longer survive to become masks that
            // (after +96) match every IPv4-mapped address.
            if (!ctype_digit($prefixStr)) { return null; }
            $prefix = (int) $prefixStr;
        } else {
            $addr   = $cidr;
            $prefix = str_contains($cidr, ':') ? 128 : 32;
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($prefix > 32) { return null; }   // IPv4 prefix length is 0..32
            $packed = inet_pton('::ffff:' . $addr);
            $prefix += 96;
        } elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($prefix > 128) { return null; }  // IPv6 prefix length is 0..128
            $packed = inet_pton($addr);
        } else {
            return null;
        }
        if ($packed === false) { return null; }
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

    /** @return Result<int> */
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

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new AdminDbDiagnostic(
                                                     'astrx.admin/db_error', DiagnosticLevel::ERROR, $e->getMessage()
                                                 )));
    }

    /**
     * Count active bans referencing the given route key.
     * Used by AdminBanlistController to prevent deleting in-use routes.
     *
     * @return Result<int>
     */
    public function countBansForRoute(string $routeKey): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM `banlist` WHERE `ban_route` = :route'
            );
            $stmt->execute([':route' => $routeKey]);
            return Result::ok((int) $stmt->fetchColumn());
        } catch (\PDOException $e) {
            return $this->err($e);
        }
    }

}
