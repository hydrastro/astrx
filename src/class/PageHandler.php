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
     * @param string $id
     *
     * @return Page|null
     */
    public function getPage(string $id)
    : Page|null {
        foreach ($this->getInternationalizedPageIds() as $i18n_id) {
            if (defined($i18n_id["id"]) && $id === constant($i18n_id["id"])) {
                $id = $i18n_id["id"];
                break;
            }
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                `id`,
                `file_name`,
                `title`,
                `description`,
                `index`,
                `follow`,
                `controller`,
                `hidden`                
            FROM
                `page`                
            WHERE
                `id` = :id"
        );
        $stmt->execute(array("id" => $id));
        $result = $stmt->fetch();
        if ($result === false) {
            return null;
        }
        $keywords = $this->getPageKeywords($id);

        return new Page(
            $result["id"],
            $result["file_name"],
            $result["title"],
            $result["description"],
            $keywords,
            filter_var($result["index"], FILTER_VALIDATE_BOOLEAN),
            filter_var($result["follow"], FILTER_VALIDATE_BOOL),
            filter_var($result["controller"], FILTER_VALIDATE_BOOL),
            filter_var(
                $result["hidden"],
                FILTER_VALIDATE_BOOL
            )
        );
    }

    /**
     * Get Internationalized Page Ids.
     * Returns the list of i18n page ids.
     * @return array<int, array<string, string>>
     */
    public function getInternationalizedPageIds()
    : array
    {
        $stmt = $this->pdo->query(
            "SELECT
                `id`
            FROM
                `page_i18n_id`"
        );
        if ($stmt === false) {
            return array();
        }

        return $stmt->fetchAll();
    }

    /**
     * Get Page Keywords.
     * Returns an array of keywords of a given page.
     *
     * @param string $page_id Page id.
     *
     * @return array<int, string>
     */
    public function getPageKeywords(string $page_id)
    : array {
        $stmt = $this->pdo->prepare(
            "SELECT
                `keyword`.`keyword` AS `keyword`,
                `keyword`.`i18n` AS `i18n`
            FROM
                `page_keyword`
            LEFT JOIN
                `keyword`
                    ON `page_keyword`.`keyword_id` = `keyword`.`id`
            WHERE
                `page_id` = :page_id"
        );
        $stmt->execute(array("page_id" => $page_id));
        $keywords = $stmt->fetchAll();
        $keywords_array = array();
        foreach ($keywords as $keyword) {
            if (filter_var($keyword["i18n"], FILTER_VALIDATE_BOOL) &&
                defined($keyword["keyword"])) {
                $keywords_array[] = constant($keyword["keyword"]);
            } else {
                $keywords_array[] = $keyword["keyword"];
            }
        }

        return $keywords_array;
    }

    /**
     * Get Error Page.
     * Given an error code, returns an error page.
     * @return Page
     */
    public function getErrorPage()
    : Page
    {
        return new Page(
            WORDING_ERROR,
            "error",
            ucfirst(WORDING_ERROR),
            "",
            array(),
            false,
            false,
            true,
            false
        );
    }

    public function addPage(Page $page)
    {
        $stmt = $this->pdo->prepare(
            "
        INSERT INTO `page`(`id`, `file_name`, `title`, `description`, `index`, `follow`, `controller`, `hidden`)
        VALUES (:id, :file_name, :title, :description, :index, :follow, :controller, :hidden)"
        );
        $stmt->execute(array(
                           "id" => $page->id,
                           "file_name" => $page->file_name,
                           "title" => $page->title,
                           "description" => $page->description,
                           "index" => $page->index,
                           "follow" => $page->follow,
                           "hidden" => $page->hidden
                       ));
        foreach ($page->keywords as $keyword) {
            $this->addKeyword($page->id, $keyword);
        }
    }

    public function addKeyword(string $page_id, string $keyword)
    : void {
        $stmt = $this->pdo->prepare(
            "
        INSERT INTO "
        );
    }

    public function editPage(string $id, Page $page)
    {
    }

    public function deletePage(string $id)
    {
    }
}
