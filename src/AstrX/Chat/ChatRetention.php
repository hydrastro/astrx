<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Retention\RetentionSource;
use AstrX\Retention\RetentionTarget;
use AstrX\Retention\UploadStore;

/**
 * What the chat keeps, for the retention engine: its report queue ages out by
 * the operator's window; messages and private messages carry their own expiry
 * (set from ChatConfig), which the engine just runs eagerly; and image
 * attachments live in the chat upload directory, named by chat_attachment.
 */
final class ChatRetention implements RetentionSource
{
    public function __construct(private readonly ChatConfig $config) {}

    public function retentionTargets(): array
    {
        return [
            new RetentionTarget('chat_report',  'chat_report',  'created_at', RetentionTarget::AGE),
            new RetentionTarget('chat_message', 'chat_message', 'expires_at', RetentionTarget::EXPIRY),
            new RetentionTarget('chat_pm',      'chat_pm',      'expires_at', RetentionTarget::EXPIRY),
        ];
    }

    public function uploadStores(): array
    {
        return [new UploadStore($this->config->uploadDir(), ['SELECT `stored_name` AS n FROM `chat_attachment`'])];
    }
}
