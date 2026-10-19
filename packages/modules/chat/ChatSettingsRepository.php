<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Chat\Diagnostic\ChatDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data-access for `chat_settings` — per-identity display preferences.
 * Absent rows mean "use the configured defaults" (resolved in ChatSettingsService).
 */
final class ChatSettingsRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<array<string,mixed>|null> */
    public function find(string $ident): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ident, refresh_secs, messages_shown, show_timestamps, font_size, text_color, link_conversion,
                        bg_color, font_family, sort_dir, hide_chatters, incognito, timezone, notes
                   FROM chat_settings WHERE ident = :id'
            );
            $stmt->execute([':id' => $ident]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function save(
        string  $ident,
        int     $refreshSecs,
        int     $messagesShown,
        bool    $showTimestamps,
        int     $fontSize,
        ?string $textColor,
        bool    $linkConversion,
        ?string $bgColor,
        ?string $fontFamily,
        ?int    $sortDir,
        bool    $hideChatters,
        bool    $incognito,
        ?string $timezone,
        ?string $notes,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_settings
                    (ident, refresh_secs, messages_shown, show_timestamps, font_size, text_color, link_conversion,
                     bg_color, font_family, sort_dir, hide_chatters, incognito, timezone, notes)
                 VALUES (:id, :rs, :ms, :ts, :fs, :tc, :lc, :bg, :ff, :sd, :hc, :ic, :tz, :no)
                 ON DUPLICATE KEY UPDATE
                    refresh_secs = VALUES(refresh_secs), messages_shown = VALUES(messages_shown),
                    show_timestamps = VALUES(show_timestamps), font_size = VALUES(font_size),
                    text_color = VALUES(text_color), link_conversion = VALUES(link_conversion),
                    bg_color = VALUES(bg_color), font_family = VALUES(font_family),
                    sort_dir = VALUES(sort_dir), hide_chatters = VALUES(hide_chatters),
                    incognito = VALUES(incognito), timezone = VALUES(timezone), notes = VALUES(notes)'
            );
            $stmt->execute([
                ':id' => $ident,
                ':rs' => $refreshSecs,
                ':ms' => $messagesShown,
                ':ts' => $showTimestamps ? 1 : 0,
                ':fs' => $fontSize,
                ':tc' => $textColor,
                ':lc' => $linkConversion ? 1 : 0,
                ':bg' => $bgColor,
                ':ff' => $fontFamily,
                ':sd' => $sortDir,
                ':hc' => $hideChatters ? 1 : 0,
                ':ic' => $incognito ? 1 : 0,
                ':tz' => $timezone,
                ':no' => $notes,
            ]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ChatDbDiagnostic(
            'astrx.chat/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
