<?php

/**
 * Class Navigation Bar.
 */
class NavigationBar
{
    /**
     * @var PDO $pdo PDO.
     */
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get Navigation Bar.
     * Returns the navigation bar array.
     * @return array<string, mixed>
     */
    public function getNavigationBar()
    : array
    {
        $stmt = $this->pdo->prepare(
            "
        SELECT
            `navigation_bar_entry`.`id`,
            `internal`,
            `name`,
            `i18n`,
            `page_id`,
            `url`
        FROM
            `navigation_bar_entry`
        LEFT JOIN
                `navigation_bar_internal`
        ON `navigation_bar_internal`.`id` = `navigation_bar_entry`.`id`
        LEFT JOIN
                `navigation_bar_external`
        ON `navigation_bar_internal`.`id` = `navigation_bar_entry`.`id`
        ORDER BY `navigation_bar_entry`.`id`
        "
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function insertInternalEntry()
    : void
    {
    }

    public function insertExternalEntry()
    : void
    {
    }

    public function swapEntries(int $first_id, int $second_id)
    : void {
    }
}