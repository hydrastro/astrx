<?php
declare(strict_types=1);

namespace AstrX\Page;

use PDO;

final class PageHandler
{
    public function __construct(private PDO $pdo) {}

    public function getPage(int $id): ?Page
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                `id`,
                `url_id`,
                `i18n`,
                `file_name`,
                `template`,
                `controller`,
                `hidden`,
                `comments`,
                `index`,
                `follow`,
                `title`,
                `description`,
                `template_file_name`
             FROM `resolved_page`
             WHERE `id` = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_array($row)) {
            return null;
        }
        /** @var array<string,mixed> $row */

        $pid = (is_int($row['id']) ? $row['id'] : 0);
        $anc = $this->getPageAncestors($pid);
        $kw  = $this->getPageKeywords($pid);

        return new Page(
            id: $pid,
            urlId: (is_scalar($row['url_id']) ? (string)$row['url_id'] : ''),
            i18n: (bool)$row['i18n'],
            fileName: (is_scalar($row['file_name']) ? (string)$row['file_name'] : ''),
            template: (bool)$row['template'],
            controller: (bool)$row['controller'],
            hidden: (bool)$row['hidden'],
            comments: (bool)$row['comments'],
            ancestors: $anc,
            index: (bool)$row['index'],
            follow: (bool)$row['follow'],
            title: (is_scalar($row['title']) ? (string)$row['title'] : ''),
            description: (is_scalar($row['description']) ? (string)$row['description'] : ''),
            keywords: $kw,
            templateFileName: (is_scalar($row['template_file_name']) ? (string)$row['template_file_name'] : ''),
        );
    }

    public function getPageIdFromUrlId(string $urlId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT `id` FROM `page` WHERE `url_id` = :u");
        $stmt->execute(['u' => $urlId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_array($row)) {
            return null;
        }
        /** @var array<string,mixed> $row */
        if (!isset($row['id'])) {
            return null;
        }
        return (is_int($row['id']) ? $row['id'] : 0);
    }

    /** @return list<array{id:int,url_id:string}> */
    public function getInternationalizedPageIds(): array
    {
        $stmt = $this->pdo->prepare("SELECT `id`, `url_id` FROM `page` WHERE `i18n` = :i18n");
        $stmt->execute(['i18n' => true]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var list<array<string,mixed>> $rows */

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (is_int($r['id']) ? $r['id'] : 0),
                'url_id' => (is_scalar($r['url_id']) ? (string)$r['url_id'] : ''),
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,url_id:string,i18n:bool,file_name:string}> */
    public function getPageAncestors(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `ancestor` as `id`, `url_id`, `i18n`, `file_name`
             FROM `page_closure`
             LEFT JOIN `page` ON `page`.`id` = `ancestor`
             WHERE `descendant` = :id"
        );
        $stmt->execute(['id' => $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var list<array<string,mixed>> $rows */
        if ($rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'        => (is_int($r['id']) ? $r['id'] : 0),
                'url_id'    => (is_scalar($r['url_id']) ? (string)$r['url_id'] : ''),
                'i18n'      => (bool)($r['i18n'] ?? false),
                'file_name' => (is_scalar($r['file_name']) ? (string)$r['file_name'] : ''),
            ];
        }
        return $out;
    }

    /** @return list<array{keyword:string,i18n:int|bool}> */
    public function getPageKeywords(int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `keyword`, `i18n`
             FROM `page_keyword`
             LEFT JOIN `keyword` ON `keyword`.`id` = `keyword_id`
             WHERE `page_id` = :id"
        );
        $stmt->execute(['id' => $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var list<array<string,mixed>> $rows */

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'keyword' => (is_scalar($r['keyword']) ? (string)$r['keyword'] : ''),
                'i18n' => is_bool($r['i18n'] ?? null) ? (int)$r['i18n'] : (is_int($r['i18n'] ?? null) ? $r['i18n'] : 0),
            ];
        }
        return $out;
    }

    /**
     * Find a direct child of $parentId whose url_id translates to $slug
     * in the current locale (or matches as a raw url_id).
     *
     * Used by ContentManager to resolve sub-path URLs like /en/user/login.
     */
    public function getChildPageBySlug(int $parentId, string $slug): ?Page
    {
        // Fetch all non-hidden descendants of $parentId whose raw url_id matches $slug.
        $stmt = $this->pdo->prepare(
            "SELECT p.id
               FROM `page` p
               JOIN `page_closure` pc ON pc.descendant = p.id
              WHERE pc.ancestor = :parent
                AND p.id       != :parent2
                AND p.hidden    = 0
                AND p.url_id   = :slug"
        );
        $stmt->execute([':parent' => $parentId, ':parent2' => $parentId, ':slug' => $slug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        /** @var list<array<string,mixed>> $rows */

        if ($rows === []) {
            return null;
        }

        // Filter to direct children only (depth=1) in PHP to avoid duplicate
        // named placeholders in the sub-query. A direct child of $parentId has
        // exactly one ancestor that is itself a descendant of $parentId:
        // $parentId itself.
        foreach ($rows as $row) {
            $idV = $row['id'] ?? 0; $id = is_int($idV) ? $idV : 0;
            $depthStmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS cnt
                   FROM `page_closure`
                  WHERE descendant = :desc
                    AND ancestor  != :desc2
                    AND ancestor IN (
                        SELECT descendant FROM `page_closure` WHERE ancestor = :anc
                    )"
            );
            $depthStmt->execute([':desc' => $id, ':desc2' => $id, ':anc' => $parentId]);
            $depthRow = $depthStmt->fetch(PDO::FETCH_ASSOC);
            // cnt=1 means only $parentId sits between root and this page → direct child
            if (is_array($depthRow) && (is_int($depthRow['cnt'] ?? null) ? $depthRow['cnt'] : 0) === 1) {
                return $this->getPage($id);
            }
        }

        return null;
    }

    public function getFallbackErrorPage(string $errorUrlId = 'WORDING_ERROR'): Page
    {
        return new Page(
            id: 0,
            urlId: $errorUrlId,
            i18n: true,
            fileName: 'error',
            template: true,
            controller: true,
            hidden: true
        );
    }
}
