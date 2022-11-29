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
     * Note: doing left joins is 1/3 faster than making 5 queries.
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
                `page`.`id`,
                `url_id`,
                `i18n`,
                `page`.`file_name`,
                `template`,
                `controller`,
                `hidden`,
                `index`,
                `follow`,
                `title`,
                `description`,
                `template`.`file_name` as `template_file_name`
            FROM
                `page`
            LEFT JOIN 
                `page_robots`
            ON
                `page_robots`.`page_id` = `page`.`id`
            LEFT JOIN 
                `page_meta`    
            ON
                `page_meta`.`page_id` = `page`.`id`
            LEFT JOIN
                `page_template`
            ON 
                `page_template`.`page_id` = `page`.`id`
            LEFT JOIN
                `template`
            ON
                `page_template`.`template_id` = `template`.`id`
            WHERE
                `page`.`id` = :id"
        );
        $stmt->execute(array("id" => $id));
        $result = $stmt->fetch();
        if ($result === false) {
            return null;
        }
        assert(is_array($result));

        $ancestors = $this->getPageAncestors($result["id"]);
        $keywords = $this->getPageKeywords($result["id"]);

        return new Page(
            $result["id"],
            $result["url_id"],
            (bool)$result["i18n"],
            $result["file_name"],
            (bool)$result["template"],
            (bool)$result["controller"],
            (bool)$result["hidden"],
            $ancestors,
            (bool)$result["index"],
            (bool)$result["follow"],
            (string)$result["title"],
            (string)$result["description"],
            $keywords,
            (string)$result["template_file_name"]
        );
    }

    /**
     * Load Page Keywords
     * Loads a given page's keywords.
     * Returns: array(array(string keyword, bool i18n))
     *
     * @param int $id
     *
     * @return array<int, array<string, mixed>>
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

        return $stmt->fetchAll();
    }

    /**
     * Get Page Ancestors.
     * Given a page id, returns the page ancestors.
     * Returns: array(array(int id, string url_id, bool i18n))
     *
     * @param int $id
     *
     * @return array<int, array<string, mixed>>
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
        if ($result === array()) {
            $fallback = $this->getPage($id);
            assert($fallback instanceof Page);

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
     * Result: array(array(int id, string url_id))
     * @return array<int, array<string, mixed>>
     */
    public function getInternationalizedPageIds()
    : array
    {
        $stmt = $this->pdo->prepare(
            "
        SELECT
            `id`,
            `url_id`
        FROM
            `page`
        WHERE
            `i18n` = 1"
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get Page ID From URL ID.
     * Returns a non-internationalized page id given its url id.
     *
     * @param string $url_id
     *
     * @return int|null
     */
    public function getPageIdFromUrlId(string $url_id)
    : int|null {
        $stmt = $this->pdo->prepare(
            "
        SELECT
            `id`
        FROM
            `page`
        WHERE
            `url_id` = :url_id"
        );
        $stmt->execute(array("url_id" => $url_id));
        $result = $stmt->fetch();
        if ($result === false) {
            return null;
        }
        assert(is_array($result));

        return $result["id"];
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

    /**
     * Get Error Page.
     * Returns a hardcoded Page class for the error pages, when things go
     * horribly wrong.
     * @return Page
     */
    public function getErrorPage()
    : Page
    {
        // The page id here shouldn't really matter, also because this
        // function is supposed to be called when the database connection is
        // messed up. The page meta, title, keywords and description are left
        // to be handled by the page's controller.
        return new Page(
            0,
            WORDING_ERROR,
            true,
            "error",
            true,
            true,
            true,
            array(array("id" => 1, "url_id" => "WORDING_ERROR", "i18n" => true))
        );
    }
}
