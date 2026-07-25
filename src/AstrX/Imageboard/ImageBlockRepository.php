<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Imageboard\Diagnostic\ImageboardDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data-access for `board_image_block`: the moderator blocklist that rejects a
 * known image on upload. A block keys on the exact content hash (sha256) and/or
 * the perceptual hash (ahash); either alone is a valid block. `ahash` is stored
 * as an unsigned 64-bit value (sprintf('%u')) to match ImageRepository.
 */
final class ImageBlockRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * The most recent blocks (capped), newest first.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function all(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, sha256, ahash, reason,
                        UNIX_TIMESTAMP(created_at) AS created_ts
                   FROM board_image_block
                  ORDER BY created_at DESC, id DESC
                  LIMIT 200'
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Add a block. Pass a 64-char sha256 for an exact block and/or a non-zero
     * ahash for a perceptual one.
     *
     * @return Result<bool>
     */
    public function create(string $sha256, int $ahash, string $reason): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO board_image_block (sha256, ahash, reason)
                 VALUES (:sha, :ah, :reason)'
            )->execute([
                ':sha'    => substr($sha256, 0, 64),
                ':ah'     => sprintf('%u', $ahash),
                ':reason' => mb_substr($reason, 0, 255),
            ]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function remove(int $blockId): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM board_image_block WHERE id = :id')
                ->execute([':id' => $blockId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardDbDiagnostic(
            'astrx.imageboard/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
