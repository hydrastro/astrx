<?php
declare(strict_types=1);

namespace AstrX\News;

use AstrX\News\Diagnostic\NewsDbDiagnostic;
use AstrX\Pagination\Pagination;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Pure data-access layer for the `news` table.
 *
 * Every method returns a Result so callers can drain failures into the
 * DiagnosticsCollector without try/catch at the call site.
 *
 * Schema expected (tables.sql):
 *   news(id INT, title VARCHAR, content TEXT, created_at INT, hidden TINYINT)
 */
final class NewsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch a paginated, ordered page of visible news items.
     *
     * @return Result<list<array{id:int,title:string,content:string,created_at:int}>>
     */
    public function fetchPage(Pagination $pagination): Result
    {
        try {
            $order = $pagination->descending ? 'DESC' : 'ASC';

            if ($pagination->isUnpaged()) {
                $stmt = $this->pdo->prepare(
                    "SELECT id, title, content, created_at
                       FROM news
                      WHERE hidden = 0
                      ORDER BY created_at {$order}"
                );
                $stmt->execute();
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT id, title, content, created_at
                       FROM news
                      WHERE hidden = 0
                      ORDER BY created_at {$order}
                      LIMIT :limit OFFSET :offset"
                );
                $stmt->bindValue(':limit',  $pagination->perPage,  PDO::PARAM_INT);
                $stmt->bindValue(':offset', $pagination->offset(), PDO::PARAM_INT);
                $stmt->execute();
            }

            /** @var list<array{id:int,title:string,content:string,created_at:int}> */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Result::ok($rows);
        } catch (PDOException $e) {
            return Result::err([], $this->pdoDiagnostic($e));
        }
    }

    /**
     * Count all visible news items (for page count calculation).
     *
     * @return Result<int>
     */
    public function countVisible(): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(id) FROM news WHERE hidden = 0');
            $stmt->execute();

            return Result::ok((int) $stmt->fetchColumn());
        } catch (PDOException $e) {
            return Result::err(0, $this->pdoDiagnostic($e));
        }
    }

    /**
     * Fetch a single visible news item by ID.
     *
     * @return Result<array{id:int,title:string,content:string,created_at:int}|null>
     */
    public function findById(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, title, content, created_at
                   FROM news
                  WHERE id = :id AND hidden = 0'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return Result::ok($row !== false ? $row : null);
        } catch (PDOException $e) {
            return Result::err(null, $this->pdoDiagnostic($e));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function pdoDiagnostic(PDOException $e): Diagnostics
    {
        return Diagnostics::of(new NewsDbDiagnostic(
                                   NewsDbDiagnostic::ID,
                                   NewsDbDiagnostic::LEVEL,
                                   $e->getMessage(),
                               ));
    }

// =========================================================================
// Admin write operations
// =========================================================================

/**
 * Fetch ALL news (including hidden) for the admin listing.
 *
 * @return Result<list<array{id:int,title:string,created_at:string,hidden:int}>>
 */
public function fetchAllAdmin(): Result
{
    try {
        $stmt = $this->pdo->query(
            'SELECT id, title, hidden, created_at
                   FROM news ORDER BY created_at DESC'
        );
        return Result::ok($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        return Result::err([], $this->pdoDiagnostic($e));
    }
}

/**
 * Fetch a single news item by ID regardless of hidden status.
 *
 * @return Result<array{id:int,title:string,content:string,created_at:string,hidden:int}|null>
 */
public function findByIdAdmin(int $id): Result
{
    try {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, content, hidden, created_at FROM news WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return Result::ok($row !== false ? $row : null);
    } catch (PDOException $e) {
        return Result::err(null, $this->pdoDiagnostic($e));
    }
}

/**
 * Create a new news post. Returns the new id.
 *
 * @return Result<int>
 */
public function create(string $title, string $content, bool $hidden = false): Result
{
    try {
        $stmt = $this->pdo->prepare(
            'INSERT INTO news (title, content, hidden) VALUES (:title, :content, :hidden)'
        );
        $stmt->execute([':title' => $title, ':content' => $content, ':hidden' => (int) $hidden]);
        return Result::ok((int) $this->pdo->lastInsertId());
    } catch (PDOException $e) {
        return Result::err(0, $this->pdoDiagnostic($e));
    }
}

/** @return Result<true> */
public function update(int $id, string $title, string $content, bool $hidden): Result
{
    try {
        $stmt = $this->pdo->prepare(
            'UPDATE news SET title = :title, content = :content, hidden = :hidden WHERE id = :id'
        );
        $stmt->execute([':title' => $title, ':content' => $content,
                        ':hidden' => (int) $hidden, ':id' => $id]);
        return Result::ok(true);
    } catch (PDOException $e) {
        return Result::err(false, $this->pdoDiagnostic($e));
    }
}

/** @return Result<true> */
public function delete(int $id): Result
{
    try {
        $stmt = $this->pdo->prepare('DELETE FROM news WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return Result::ok(true);
    } catch (PDOException $e) {
        return Result::err(false, $this->pdoDiagnostic($e));
    }
}
}