<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
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
            `id`,
            `internal`,
            `name`,
            `i18n`,
            `page_id`,
            `url`
        FROM
            `resolved_navigation_bar`
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