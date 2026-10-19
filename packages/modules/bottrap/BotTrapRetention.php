<?php
declare(strict_types=1);

namespace AstrX\BotTrap;

use AstrX\Retention\RetentionSource;
use AstrX\Retention\RetentionTarget;

/** What the bot trap keeps, for the retention engine: its hit log ages out by the operator's window. */
final class BotTrapRetention implements RetentionSource
{
    public function retentionTargets(): array
    {
        return [new RetentionTarget('bot_trap_log', 'bot_trap_log', 'created_at', RetentionTarget::AGE)];
    }

    public function uploadStores(): array
    {
        return [];
    }
}
