<?php
declare(strict_types=1);

namespace AstrX\Tipline;

use AstrX\Retention\RetentionSource;
use AstrX\Retention\RetentionTarget;

/** What the tip line keeps, for the retention engine: sealed tips age out by the operator's window. */
final class TiplineRetention implements RetentionSource
{
    public function retentionTargets(): array
    {
        return [new RetentionTarget('tipline', 'tipline', 'created_at', RetentionTarget::AGE)];
    }

    public function uploadStores(): array
    {
        return [];
    }
}
