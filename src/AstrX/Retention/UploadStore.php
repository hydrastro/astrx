<?php
declare(strict_types=1);

namespace AstrX\Retention;

/**
 * An upload directory whose files belong to database rows, and the queries
 * that list the file names those rows still reference. The orphan reaper
 * deletes a file only when NO store's query references it, so two modules may
 * share a directory safely.
 *
 * Each query returns one column of file names. They are module constants,
 * never input.
 */
final class UploadStore
{
    /**
     * @param list<string> $referencedNameQueries
     */
    public function __construct(
        public readonly string $dir,
        public readonly array $referencedNameQueries,
    ) {}
}
