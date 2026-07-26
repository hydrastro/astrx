<?php
declare(strict_types=1);

namespace AstrX\Content;

use AstrX\Content\Diagnostic\ContentDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data access for the content module: the Markdown pages (`content_page`) and
 * their `[[wiki]]` link graph (`content_link`).
 *
 * Saving a page re-extracts its outbound links and (re)resolves inbound links,
 * so `content_link.to_id` is always the resolved target id or NULL for a broken
 * link — which is exactly what the backlinks panel, the graph and the broken-link
 * checker read. All queries are bound; native prepares mean integer columns come
 * back as ints.
 *
 * @phpstan-type PageRow array{id:int,slug:string,title:string,body:string,visible:bool,updated_at:string}
 */
final class ContentPageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<?array{id:int,slug:string,title:string,body:string,visible:bool,updated_at:string}> */
    public function bySlug(string $slug): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM `content_page` WHERE `slug` = :s LIMIT 1');
            $stmt->bindValue(':s', $slug);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return Result::ok(null);
            }
            /** @var array<string,mixed> $row */
            return Result::ok($this->row($row));
        } catch (PDOException $e) {
            return Result::err(null, $this->diag($e));
        }
    }

    /** @return Result<?array{id:int,slug:string,title:string,body:string,visible:bool,updated_at:string}> */
    public function byId(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM `content_page` WHERE `id` = :i LIMIT 1');
            $stmt->bindValue(':i', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return Result::ok(null);
            }
            /** @var array<string,mixed> $row */
            return Result::ok($this->row($row));
        } catch (PDOException $e) {
            return Result::err(null, $this->diag($e));
        }
    }

    /**
     * @param bool $visibleOnly true for the public index, false for the admin list
     * @return Result<list<array{id:int,slug:string,title:string,visible:bool,updated_at:string}>>
     */
    public function all(bool $visibleOnly): Result
    {
        try {
            $sql = 'SELECT `id`, `slug`, `title`, `visible`, `updated_at` FROM `content_page`';
            if ($visibleOnly) {
                $sql .= ' WHERE `visible` = 1';
            }
            $sql .= ' ORDER BY `title` = \'\', `title` ASC, `slug` ASC';
            $stmt = $this->pdo->query($sql);
            $out  = [];
            if ($stmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = [
                        'id'         => $this->i($r['id'] ?? null),
                        'slug'       => $this->s($r['slug'] ?? null),
                        'title'      => $this->s($r['title'] ?? null),
                        'visible'    => (bool) ($r['visible'] ?? 0),
                        'updated_at' => $this->s($r['updated_at'] ?? null),
                    ];
                }
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Insert (id=0) or update a page, then rebuild its link graph. Returns the id.
     *
     * @return Result<int>
     */
    public function save(int $id, string $slug, string $title, string $body, bool $visible): Result
    {
        try {
            if ($id > 0) {
                $stmt = $this->pdo->prepare(
                    'UPDATE `content_page` SET `slug`=:s, `title`=:t, `body`=:b, `visible`=:v WHERE `id`=:i'
                );
                $stmt->bindValue(':i', $id, PDO::PARAM_INT);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO `content_page` (`slug`, `title`, `body`, `visible`) VALUES (:s, :t, :b, :v)'
                );
            }
            $stmt->bindValue(':s', $slug);
            $stmt->bindValue(':t', $title);
            $stmt->bindValue(':b', $body);
            $stmt->bindValue(':v', $visible ? 1 : 0, PDO::PARAM_INT);
            $stmt->execute();
            if ($id === 0) {
                $id = (int) $this->pdo->lastInsertId();
            }

            $this->rebuildOutboundLinks($id, $slug, Markdown::wikiTargets($body));
            // A new/renamed page resolves any inbound links that were waiting on it.
            $res = $this->pdo->prepare('UPDATE `content_link` SET `to_id`=:i WHERE `to_slug`=:s AND `to_id` IS NULL');
            $res->bindValue(':i', $id, PDO::PARAM_INT);
            $res->bindValue(':s', $slug);
            $res->execute();

            return Result::ok($id);
        } catch (PDOException $e) {
            return Result::err(0, $this->diag($e));
        }
    }

    /** @return Result<bool> */
    public function delete(int $id): Result
    {
        try {
            // Inbound links to this page become broken (no FK cascade on to_id).
            $u = $this->pdo->prepare('UPDATE `content_link` SET `to_id` = NULL WHERE `to_id` = :i');
            $u->bindValue(':i', $id, PDO::PARAM_INT);
            $u->execute();
            // Outbound links cascade via the from_id foreign key.
            $d = $this->pdo->prepare('DELETE FROM `content_page` WHERE `id` = :i');
            $d->bindValue(':i', $id, PDO::PARAM_INT);
            $d->execute();
            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Pages that link TO the given page ("what links here").
     *
     * @return Result<list<array{slug:string,title:string}>>
     */
    public function backlinks(int $toId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.`slug`, p.`title`
                   FROM `content_link` l
                   JOIN `content_page` p ON p.`id` = l.`from_id`
                  WHERE l.`to_id` = :i AND p.`visible` = 1 AND l.`from_id` <> :i2
                  ORDER BY p.`title` ASC'
            );
            $stmt->bindValue(':i', $toId, PDO::PARAM_INT);
            $stmt->bindValue(':i2', $toId, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($this->slugTitleRows($rows));
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Every unresolved link — the broken-link report.
     *
     * @return Result<list<array{from_slug:string,from_title:string,to_slug:string}>>
     */
    public function brokenLinks(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT p.`slug` AS from_slug, p.`title` AS from_title, l.`to_slug` AS to_slug
                   FROM `content_link` l
                   JOIN `content_page` p ON p.`id` = l.`from_id`
                  WHERE l.`to_id` IS NULL
                  ORDER BY p.`title` ASC, l.`to_slug` ASC'
            );
            $out = [];
            if ($stmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = [
                        'from_slug'  => $this->s($r['from_slug'] ?? null),
                        'from_title' => $this->s($r['from_title'] ?? null),
                        'to_slug'    => $this->s($r['to_slug'] ?? null),
                    ];
                }
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Graph data: visible nodes and the resolved edges between them.
     *
     * @return Result<array{nodes:list<array{id:int,slug:string,title:string}>,edges:list<array{from:int,to:int}>}>
     */
    public function graph(): Result
    {
        try {
            $nodes = [];
            $nstmt = $this->pdo->query('SELECT `id`, `slug`, `title` FROM `content_page` WHERE `visible` = 1');
            if ($nstmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($nstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $nodes[] = [
                        'id'    => $this->i($r['id'] ?? null),
                        'slug'  => $this->s($r['slug'] ?? null),
                        'title' => $this->s($r['title'] ?? null),
                    ];
                }
            }
            $edges = [];
            $estmt = $this->pdo->query(
                'SELECT l.`from_id` AS f, l.`to_id` AS t
                   FROM `content_link` l
                   JOIN `content_page` a ON a.`id` = l.`from_id` AND a.`visible` = 1
                   JOIN `content_page` b ON b.`id` = l.`to_id`  AND b.`visible` = 1'
            );
            if ($estmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($estmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $edges[] = ['from' => $this->i($r['f'] ?? null), 'to' => $this->i($r['t'] ?? null)];
                }
            }
            return Result::ok(['nodes' => $nodes, 'edges' => $edges]);
        } catch (PDOException $e) {
            return Result::err(['nodes' => [], 'edges' => []], $this->diag($e));
        }
    }

    // -------------------------------------------------------------------------

    /** @param list<string> $targets */
    private function rebuildOutboundLinks(int $fromId, string $ownSlug, array $targets): void
    {
        $del = $this->pdo->prepare('DELETE FROM `content_link` WHERE `from_id` = :i');
        $del->bindValue(':i', $fromId, PDO::PARAM_INT);
        $del->execute();

        $ins = $this->pdo->prepare(
            'INSERT INTO `content_link` (`from_id`, `to_slug`, `to_id`)
             VALUES (:f, :s, (SELECT `id` FROM `content_page` WHERE `slug` = :s2))'
        );
        foreach ($targets as $slug) {
            if ($slug === $ownSlug) {
                continue; // ignore self-links in the graph/backlinks
            }
            $ins->bindValue(':f', $fromId, PDO::PARAM_INT);
            $ins->bindValue(':s', $slug);
            $ins->bindValue(':s2', $slug);
            $ins->execute();
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{slug:string,title:string}>
     */
    private function slugTitleRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['slug' => $this->s($r['slug'] ?? null), 'title' => $this->s($r['title'] ?? null)];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int,slug:string,title:string,body:string,visible:bool,updated_at:string}
     */
    private function row(array $r): array
    {
        return [
            'id'         => $this->i($r['id'] ?? null),
            'slug'       => $this->s($r['slug'] ?? null),
            'title'      => $this->s($r['title'] ?? null),
            'body'       => $this->s($r['body'] ?? null),
            'visible'    => (bool) ($r['visible'] ?? 0),
            'updated_at' => $this->s($r['updated_at'] ?? null),
        ];
    }

    private function diag(PDOException $e): Diagnostics
    {
        return Diagnostics::of(new ContentDbDiagnostic('astrx.content/db_error', DiagnosticLevel::ERROR, $e->getMessage()));
    }

    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private function i(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}
