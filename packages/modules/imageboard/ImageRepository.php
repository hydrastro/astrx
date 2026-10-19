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
 * Data-access for `board_image`. `ahash` is stored as an unsigned 64-bit value
 * (sprintf('%u') of the sanitizer's signed hash), so near-duplicate detection
 * can use BIT_COUNT(ahash ^ ?) later without sign trouble.
 */
final class ImageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    private const COLS =
        'id, post_id, token, full_name, thumb_name, mime, byte_size, width, height,
         thumb_w, thumb_h, sha256, orig_name, spoiler';

    /**
     * @param array{token:string,full_name:string,thumb_name:string,mime:string,size:int,width:int,height:int,thumb_w:int,thumb_h:int,ahash:int,sha256:string,orig_name:string,spoiler:bool} $m
     * @return Result<int>
     */
    public function create(int $postId, array $m): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO board_image
                    (post_id, token, full_name, thumb_name, mime, byte_size, width, height,
                     thumb_w, thumb_h, ahash, sha256, orig_name, spoiler)
                 VALUES
                    (:pid, :tok, :full, :thumb, :mime, :size, :w, :h,
                     :tw, :th, :ah, :sha, :orig, :sp)'
            )->execute([
                ':pid'   => $postId,
                ':tok'   => $m['token'],
                ':full'  => $m['full_name'],
                ':thumb' => $m['thumb_name'],
                ':mime'  => $m['mime'],
                ':size'  => $m['size'],
                ':w'     => $m['width'],
                ':h'     => $m['height'],
                ':tw'    => $m['thumb_w'],
                ':th'    => $m['thumb_h'],
                ':ah'    => sprintf('%u', $m['ahash']),
                ':sha'   => $m['sha256'],
                ':orig'  => mb_substr($m['orig_name'], 0, 255),
                ':sp'    => $m['spoiler'] ? 1 : 0,
            ]);
            $raw = $this->pdo->lastInsertId();
            return Result::ok(is_numeric($raw) ? (int) $raw : 0);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Images for a set of post ids, grouped by post_id.
     *
     * @param list<int> $postIds
     * @return Result<array<int,list<array<string,mixed>>>>
     */
    public function forPosts(array $postIds): Result
    {
        if ($postIds === []) {
            return Result::ok([]);
        }
        try {
            $place = implode(',', array_fill(0, count($postIds), '?'));
            $stmt  = $this->pdo->prepare('SELECT ' . self::COLS . " FROM board_image WHERE post_id IN ($place) ORDER BY id ASC");
            $stmt->execute(array_values($postIds));
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $pid = is_numeric($row['post_id'] ?? null) ? (int) $row['post_id'] : 0;
                $out[$pid][] = $row;
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function findByToken(string $token): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM board_image WHERE token = :t LIMIT 1');
            $stmt->execute([':t' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return Result::ok(null);
            }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
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
