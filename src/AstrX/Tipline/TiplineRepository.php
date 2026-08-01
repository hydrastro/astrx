<?php
declare(strict_types=1);

namespace AstrX\Tipline;

use PDO;

/**
 * Storage for sealed anonymous tips (the `tipline` table). Rows hold only the
 * base64 sealed box and a timestamp — never plaintext, never any submitter
 * metadata (no IP, no session, no user id): a tip is unlinkable by construction.
 */
final class TiplineRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Persist one sealed (base64) tip. Returns false on a DB error (non-fatal to the caller). */
    public function store(string $sealedB64): bool
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO `tipline` (`sealed`) VALUES (:s)');
            return $stmt->execute([':s' => $sealedB64]);
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Most recent sealed tips, newest first.
     *
     * @return list<array{id:int,sealed:string,created_at:string}>
     */
    public function recent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `sealed`, `created_at` FROM `tipline` ORDER BY `id` DESC LIMIT ' . $limit
            );
            $stmt->execute();
            /** @var list<array{id:int,sealed:string,created_at:string}> $rows */
            $rows = [];
            while (true) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    break;
                }
                $rows[] = [
                    'id'         => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                    'sealed'     => is_scalar($row['sealed'] ?? null) ? (string) $row['sealed'] : '',
                    'created_at' => is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
                ];
            }
            return $rows;
        } catch (\PDOException) {
            return [];
        }
    }

    public function count(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM `tipline`');
            if ($stmt === false) {
                return 0;
            }
            $n = $stmt->fetchColumn();
            return is_numeric($n) ? (int) $n : 0;
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Delete one tip by id. */
    public function delete(int $id): bool
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM `tipline` WHERE `id` = :id');
            return $stmt->execute([':id' => $id]);
        } catch (\PDOException) {
            return false;
        }
    }

    /** Shred every stored tip. Returns the number of rows removed. */
    public function purgeAll(): int
    {
        try {
            $stmt = $this->pdo->query('DELETE FROM `tipline`');
            return $stmt === false ? 0 : $stmt->rowCount();
        } catch (\PDOException) {
            return 0;
        }
    }
}
