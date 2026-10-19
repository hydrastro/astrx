<?php
declare(strict_types=1);

namespace AstrX\Retention;

/**
 * The data a module keeps that the retention engine must be able to shred,
 * and the upload directories whose files belong to rows it deletes.
 *
 * A module extension point (manifest `extensions`). Asked of every INSTALLED
 * module, enabled or not: switching a module off does not delete its data, so
 * its retention windows must keep applying.
 */
interface RetentionSource
{
    /** @return list<RetentionTarget> */
    public function retentionTargets(): array;

    /** @return list<UploadStore> */
    public function uploadStores(): array;
}
