<?php

declare(strict_types = 1);
/**
 * Class PageHandler.
 */
class PageHandler
{
    /**
     * @param PDO $pdo PDO.
     */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get Page.
     * Given the page id, returns the page.
     *
     * @param int $id Page id.
     *
     * @return Page|null
     */
    public function getPage(int $id)
    : Page|null {
        $stmt = $this->pdo->prepare(
            "
            SELECT
                `id`,
                `url_id`,
                `i18n`,
                `file_name`,
                `controller`,
                `hidden`,
                `index`,
                `follow`,
                `title`,
                `description`
            FROM
                `page`
            LEFT JOIN 
                `page_robots`
            ON
                `page_robots`.`page_id` = `id`
            LEFT JOIN 
                `page_meta`    
            ON
                `page_meta`.`page_id` = `id`
            WHERE
                `id` = :id"
        );
        $stmt->execute(array("id" => $id));
        $result = $stmt->fetch();
        if ($result === false) {
            return null;
        }

        $ancestors = $this->getPageAncestors($result["id"]);
        $keywords = $this->getPageKeywords($result["id"]);

        // filter var ???
        return new Page(
            $result["id"],
            $result["url_id"],
            $result["i18n"],
            $result["file_name"],
            $result["controller"],
            $result["hidden"],
            $ancestors,
            $result["index"],
            $result["follow"],
            $result["title"],
            $result["description"],
            $keywords
        );
    }

    /**
     * Load Page Keywords
     * Loads a given page's keywords.
     * Returns: array(array(string keyword, bool i18n))
     *
     * @param int $id
     *
     * @return array<int, array<int, mixed>>
     */
    public function getPageKeywords(int $id)
    : array {
        $stmt = $this->pdo->prepare(
            "
        SELECT
            `keyword`,
            `i18n`
        FROM
            `page_keyword`
        LEFT JOIN
            `keyword`
        ON
            `keyword`.`id` = `keyword_id`
        WHERE
            `page_id` = :id"
        );
        $stmt->execute(array("id" => $id));
        $result = $stmt->fetchAll();
        if ($result === false) {
            // Note: this method could also return null since keywords are an
            // optional attribute of the Page class.
            return array();
        }

        return $result;
    }

    /**
     * Get Page Ancestors.
     * Given a page id, returns the page ancestors.
     * Returns: array(array(int id, string url_id, bool i18n))
     *
     * @param int $id
     *
     * @return array<int, array<int, mixed>>
     */
    public function getPageAncestors(int $id)
    : array {
        $stmt = $this->pdo->prepare(
            "
        SELECT
            `ancestor` as `id`,
            `url_id`,
            `i18n`
        FROM
            `page_closure`
        LEFT JOIN
                `page`
        ON
            `page`.`id` = `ancestor`
        WHERE
            `descendant` = :id
        "
        );
        $stmt->execute(array("id" => $id));
        $result = $stmt->fetchAll();
        if ($result === false) {
            $fallback = $this->getPage($id);

            return array(
                array(
                    "id" => $id,
                    "url_id" => $fallback->url_id,
                    "i18n" => $fallback->i18n
                )
            );
        }

        return $result;
    }

    /**
     * Get Internationalized Page Ids.
     * Returns the id and the url id of the page with internationalization
     * enabled.
     * @return array<int, array<int, mixed>>
     */
    public function getInternationalizedPageIds()
    : array
    {
        $stmt = $this->pdo->prepare(
            "
        SELECT
            `id`
        FROM
            `page`
        WHERE
            `i18n` = 1"
        );
        $stmt->execute();
        $result = $stmt->fetchAll();
        if ($result === false) {
            return array();
        }

        return $result;
    }

    public function addPage()
    : void
    {
    }

    public function editPage()
    : void
    {
    }

    public function deletePage()
    : void
    {
    }
}
