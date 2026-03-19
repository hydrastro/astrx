<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Banlist data-access — bans, routes, and rounds.
 *
 * Routes (ban_route table) define named penalty profiles: Permanent, Bad comment, etc.
 * Rounds (ban_round table) define the escalation steps within each route.
 *
 * IP bans use CIDR notation via IPv4-mapped IPv6 (see parseCidr()).
 */
final class BanlistRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // =========================================================================
    // Routes & rounds (DB-managed)
    // =========================================================================

    /** @return Result<list<array{id:int,name:string,description:string,rounds:list<array>}>> */
    public function listRoutes(): Result
    {
        try {
            $routes = $this->pdo->query(
                'SELECT id, name, description FROM banlist_route ORDER BY id'
            )->fetchAll(PDO::FETCH_ASSOC);

            $rounds = $this->pdo->query(
                'SELECT id, route_id, round_num, penalty, max_tries, check_time, enabled
                   FROM banlist_round ORDER BY route_id, round_num'
            )->fetchAll(PDO::FETCH_ASSOC);

            // Group rounds under their route
            $roundsByRoute = [];
            foreach ($rounds as $r) {
                $roundsByRoute[(int) $r['route_id']][] = $r;
            }
            $result = [];
            foreach ($routes as $route) {
                $route['rounds'] = $roundsByRoute[(int) $route['id']] ?? [];
                $result[]        = $route;
            }
            return Result::ok($result);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> new route id */
    public function addRoute(string $name, string $description): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO banlist_route (name, description) VALUES (:n, :d)'
            )->execute([':n' => $name, ':d' => $description]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function updateRoute(int $id, string $name, string $description): Result
    {
        try {
            $this->pdo->prepare(
                'UPDATE banlist_route SET name = :n, description = :d WHERE id = :id'
            )->execute([':n' => $name, ':d' => $description, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> — CASCADE deletes rounds and bans using this route */
    public function deleteRoute(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM banlist_route WHERE id = :id')
                ->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> new round id */
    public function addRound(int $routeId, int $penalty,
        int $maxTries, int $checkTime, bool $enabled): Result
    {
        try {
            // Auto-assign round_num = MAX + 1 for this route
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(round_num), -1) + 1 FROM banlist_round WHERE route_id = :rid'
            );
            $stmt->execute([':rid' => $routeId]);
            $nextNum = (int) $stmt->fetchColumn();

            $this->pdo->prepare(
                'INSERT INTO banlist_round
                    (route_id, round_num, penalty, max_tries, check_time, enabled)
                 VALUES (:rid, :rn, :p, :mt, :ct, :en)'
            )->execute([':rid' => $routeId, ':rn' => $nextNum, ':p' => $penalty,
                        ':mt' => $maxTries, ':ct' => $checkTime, ':en' => (int) $enabled]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function updateRound(int $id, int $penalty, int $maxTries,
        int $checkTime, bool $enabled): Result
    {
        try {
            $this->pdo->prepare(
                'UPDATE banlist_round
                    SET penalty = :p, max_tries = :mt, check_time = :ct, enabled = :en
                  WHERE id = :id'
            )->execute([':p' => $penalty, ':mt' => $maxTries,
                        ':ct' => $checkTime, ':en' => (int) $enabled, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<true> */
    public function deleteRound(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM banlist_round WHERE id = :id')
                ->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // =========================================================================
    // Bans — listing & lookup
    // =========================================================================

    /** @return Result<list<array<string,mixed>>> */
    public function listAll(): Result
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT b.id, b.ban_route, r.name AS route_name,
                        b.reason, b.start, b.end, b.active,
                        'user'  AS type,
                        LOWER(HEX(bu.user_id)) AS value
                   FROM banlist b
                   LEFT JOIN banlist_route r ON r.id = b.ban_route
                   JOIN banlist_user bu ON bu.ban_id = b.id
                 UNION ALL
                 SELECT b.id, b.ban_route, r.name,
                        b.reason, b.start, b.end, b.active,
                        'email', be.email
                   FROM banlist b
                   LEFT JOIN banlist_route r ON r.id = b.ban_route
                   JOIN banlist_email be ON be.ban_id = b.id
                 UNION ALL
                 SELECT b.id, b.ban_route, r.name,
                        b.reason, b.start, b.end, b.active,
                        'ip',
                        CONCAT(INET6_NTOA(bi.network), '/', bi.prefix_len)
                   FROM banlist b
                   LEFT JOIN banlist_route r ON r.id = b.ban_route
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
    public function banCidr(string $cidr, string $reason, int $route, ?string $end = null): Result
    {
        $parsed = self::parseCidr($cidr);
        if ($parsed === null) {
            return Result::err(null, Diagnostics::of(new AdminDbDiagnostic(
                                                         AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, "Invalid IP/CIDR: {$cidr}"
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
    public function banEmail(string $email, string $reason, int $route, ?string $end = null): Result
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
    public function banUser(string $hexUserId, string $reason, int $route, ?string $end = null): Result
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
    public function updateBan(int $id, string $reason, int $route, ?string $end, bool $active): Result
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
            $bits     = max(0, min(8, $prefix - $i * 8));
            $mask     = $bits === 0 ? 0x00 : (0xFF & (0xFF << (8 - $bits)));
            $result[$i] = chr(ord($packed[$i]) & $mask);
        }
        return $result;
    }

    public static function ipMatchesCidr(string $packedIp, string $packedNetwork, int $prefix): bool
    {
        return self::applyMask($packedIp, $prefix) === $packedNetwork;
    }

    // =========================================================================

    private function insertCore(string $reason, int $route, ?string $end): Result
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
                                                     AdminDbDiagnostic::ID, AdminDbDiagnostic::LEVEL, $e->getMessage()
                                                 )));
    }
}