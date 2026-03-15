<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Banlist data-access layer.
 *
 * IP bans use CIDR notation internally:
 *   banlist_ip stores (network VARBINARY(16), prefix_len TINYINT).
 *   A single IP like 192.168.1.5 is stored as /32 (IPv4) or /128 (IPv6).
 *   A subnet like 192.168.1.0/24 stores network=INET6_ATON('::ffff:192.168.1.0'), prefix_len=120
 *   (IPv4-mapped IPv6 so one table handles both families uniformly).
 *
 * Ban routes: 0=permanent, 1=bad_comment, 2=failed_login.
 */
final class BanlistRepository
{
    public const int ROUTE_PERMANENT    = 0;
    public const int ROUTE_BAD_COMMENT  = 1;
    public const int ROUTE_FAILED_LOGIN = 2;

    /** @var array<int,list<array{penalty:int,max_tries:int,check_time:int,enabled:bool}>> */
    private array $routes = [];

    /**
     * @param array<int,list<array{penalty:int,max_tries:int,check_time:int,enabled:bool}>> $routes
     */
    #[InjectConfig('routes')]
    public function setRoutes(array $routes): void
    {
        $this->routes = $routes;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    /** @return array<int,string> */
    public static function routeNames(): array
    {
        return [
            self::ROUTE_PERMANENT    => 'Permanent',
            self::ROUTE_BAD_COMMENT  => 'Bad comment',
            self::ROUTE_FAILED_LOGIN => 'Failed login',
        ];
    }

    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Helpers: CIDR parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a CIDR string into (packed network bytes, prefix length).
     * Accepts bare IPs (treated as /32 or /128) and CIDR notation.
     *
     * Returns null on invalid input.
     *
     * @return array{network:string,prefix:int}|null
     */
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

        // Normalise IPv4 to IPv4-mapped IPv6 so one table covers both
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $packed = inet_pton('::ffff:' . $addr);
            $prefix = $prefix + 96; // shift prefix into IPv6 space
        } elseif (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($addr);
        } else {
            return null;
        }

        if ($packed === false) {
            return null;
        }

        if ($prefix < 0 || $prefix > 128) {
            return null;
        }

        // Mask the address to get the true network address
        $network = self::applyMask($packed, $prefix);

        return ['network' => $network, 'prefix' => $prefix];
    }

    /** Apply a prefix mask to 16-byte packed IP. */
    private static function applyMask(string $packed, int $prefix): string
    {
        $result = str_repeat("\x00", 16);
        for ($i = 0; $i < 16; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $mask = $bits === 0 ? 0x00 : (0xFF & (0xFF << (8 - $bits)));
            $result[$i] = chr(ord($packed[$i]) & $mask);
        }
        return $result;
    }

    /**
     * Check whether a packed IP falls within a stored CIDR.
     * Done in PHP because MySQL can't easily do prefix masking on VARBINARY.
     */
    public static function ipMatchesCidr(string $packedIp, string $packedNetwork, int $prefix): bool
    {
        return self::applyMask($packedIp, $prefix) === $packedNetwork;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * List all bans with their type-specific value.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function listAll(): Result
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT b.id, b.ban_route, b.reason, b.start, b.end, b.active,
                        'user'  AS type,
                        LOWER(HEX(bu.user_id)) AS value
                   FROM banlist b JOIN banlist_user bu ON bu.ban_id = b.id
                 UNION ALL
                 SELECT b.id, b.ban_route, b.reason, b.start, b.end, b.active,
                        'email', be.email
                   FROM banlist b JOIN banlist_email be ON be.ban_id = b.id
                 UNION ALL
                 SELECT b.id, b.ban_route, b.reason, b.start, b.end, b.active,
                        'ip',
                        CONCAT(INET6_NTOA(bi.network), '/', bi.prefix_len)
                   FROM banlist b JOIN banlist_ip bi ON bi.ban_id = b.id
                 ORDER BY id DESC"
            );
            return Result::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Check if a given IP string is covered by any active ban.
     * Loads all IP bans and checks in PHP (CIDR matching on VARBINARY is complex in SQL).
     *
     * @return Result<int|null>  ban id or null
     */
    public function findActiveBanForIp(string $ip): Result
    {
        $parsed = self::parseCidr($ip);
        if ($parsed === null) {
            return Result::ok(null);
        }
        $packedIp = $parsed['network']; // /128 for single IP

        try {
            $stmt = $this->pdo->query(
                'SELECT b.id, bi.network, bi.prefix_len
                   FROM banlist b JOIN banlist_ip bi ON bi.ban_id = b.id
                  WHERE b.active = 1'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    /** @return Result<int|null> ban id or null */
    public function findActiveBanForEmail(string $email): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT b.id FROM banlist b
                   JOIN banlist_email be ON be.ban_id = b.id
                  WHERE b.active = 1 AND LOWER(be.email) = LOWER(:email)
                  LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return Result::ok($row !== false ? (int) $row['id'] : null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Add an IP/CIDR ban. Input: any valid CIDR string or bare IP.
     * e.g. "192.168.1.5", "10.0.0.0/8", "2001:db8::/32"
     *
     * @return Result<int> new ban id
     */
    public function banCidr(string $cidr, string $reason, int $route, ?string $end = null): Result
    {
        $parsed = self::parseCidr($cidr);
        if ($parsed === null) {
            return Result::err(null, Diagnostics::of(new AdminDbDiagnostic(
                                                         AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL,
                                                         "Invalid IP/CIDR: {$cidr}"
                                                     )));
        }

        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) {
            return $coreResult;
        }
        $banId = $coreResult->unwrap();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO banlist_ip (ban_id, network, prefix_len) VALUES (:id, :net, :prefix)'
            );
            $stmt->execute([
                               ':id'     => $banId,
                               ':net'    => $parsed['network'],
                               ':prefix' => $parsed['prefix'],
                           ]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> new ban id */
    public function banEmail(string $email, string $reason, int $route, ?string $end = null): Result
    {
        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) {
            return $coreResult;
        }
        $banId = $coreResult->unwrap();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO banlist_email (ban_id, email) VALUES (:id, :email)'
            );
            $stmt->execute([':id' => $banId, ':email' => $email]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> new ban id */
    public function banUser(string $hexUserId, string $reason, int $route, ?string $end = null): Result
    {
        $coreResult = $this->insertCore($reason, $route, $end);
        if (!$coreResult->isOk()) {
            return $coreResult;
        }
        $banId = $coreResult->unwrap();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO banlist_user (ban_id, user_id) VALUES (:id, UNHEX(:uid))'
            );
            $stmt->execute([':id' => $banId, ':uid' => $hexUserId]);
            return Result::ok($banId);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function setActive(int $id, bool $active): Result
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE banlist SET active = :a WHERE id = :id');
            $stmt->execute([':a' => (int) $active, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function delete(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM banlist WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // -------------------------------------------------------------------------

    private function insertCore(string $reason, int $route, ?string $end): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO banlist (ban_route, reason, end) VALUES (:route, :reason, :end)'
            );
            $stmt->execute([':route' => $route, ':reason' => $reason, ':end' => $end]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new AdminDbDiagnostic(
                                                     AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, $e->getMessage()
                                                 )));
    }
}