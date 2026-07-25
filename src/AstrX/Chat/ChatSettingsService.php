<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Result\Result;

/**
 * Resolves and persists per-identity display settings, falling back to the
 * admin-configured defaults and clamping everything to safe ranges.
 */
final class ChatSettingsService
{
    private const FONT_MIN = 8;
    private const FONT_MAX = 28;
    private const MSG_MAX   = 200;

    public function __construct(
        private readonly ChatSettingsRepository $repo,
        private readonly ChatConfig             $config,
    ) {}

    /**
     * @return array{refresh_secs:int, messages_shown:int, show_timestamps:bool, font_size:int, text_color:?string, link_conversion:bool, bg_color:?string, font_family:?string, sort_dir:?int, hide_chatters:bool, incognito:bool, timezone:?string, notes:?string}
     */
    public function effective(string $ident): array
    {
        $row = null;
        $r   = $this->repo->find($ident);
        if ($r->isOk()) {
            $row = $r->unwrap();
        }

        // Global admin defaults, applied whenever a user has not chosen their own
        // (a settings row with a blank field falls back the same way as no row).
        $colorDefault = $this->config->defaultColor();
        $colorDefault = $colorDefault !== '' ? $colorDefault : null;
        $bgDefault    = $this->config->defaultBgColor();
        $bgDefault    = $bgDefault !== '' ? $bgDefault : null;
        $fontDefault  = $this->normalizeFont($this->config->defaultFontFamily());
        $tzDefault    = ChatStyles::isValidTimezone($this->config->defaultTimezone())
            ? $this->config->defaultTimezone() : null;

        if ($row === null) {
            return [
                'refresh_secs'    => $this->config->defaultRefreshSecs(),
                'messages_shown'  => $this->config->messagesShown(),
                'show_timestamps' => $this->config->showTimestampsDefault(),
                'font_size'       => 16,
                'text_color'      => $colorDefault,
                'link_conversion' => $this->config->linkConversion(),
                'bg_color'        => $bgDefault,
                'font_family'     => $fontDefault,
                'sort_dir'        => null,
                'hide_chatters'   => false,
                'incognito'       => false,
                'timezone'        => $tzDefault,
                'notes'           => null,
            ];
        }

        $tcRaw = $row['text_color'] ?? null;
        $tc    = is_string($tcRaw) && $tcRaw !== '' ? $tcRaw : $colorDefault;
        $bgRaw = $row['bg_color'] ?? null;
        $bg    = is_string($bgRaw) && $bgRaw !== '' ? $bgRaw : $bgDefault;
        $ffRaw = $row['font_family'] ?? null;
        $ff    = $this->normalizeFont(is_string($ffRaw) ? $ffRaw : '') ?? $fontDefault;
        $sdRaw = $row['sort_dir'] ?? null;
        $sd    = ($sdRaw === null || $sdRaw === '') ? null : ($this->int($row, 'sort_dir', 0) === 0 ? 0 : 1);

        return [
            'refresh_secs'    => $this->clampRefresh($this->int($row, 'refresh_secs', $this->config->defaultRefreshSecs())),
            'messages_shown'  => max(1, min(self::MSG_MAX, $this->int($row, 'messages_shown', $this->config->messagesShown()))),
            'show_timestamps' => (bool) ($row['show_timestamps'] ?? 1),
            'font_size'       => max(self::FONT_MIN, min(self::FONT_MAX, $this->int($row, 'font_size', 16))),
            'text_color'      => $tc,
            'link_conversion' => (bool) ($row['link_conversion'] ?? 1),
            'bg_color'        => $bg,
            'font_family'     => $ff,
            'sort_dir'        => $sd,
            'hide_chatters'   => (bool) ($row['hide_chatters'] ?? 0),
            'incognito'       => (bool) ($row['incognito'] ?? 0),
            'timezone'        => (is_string($row['timezone'] ?? null) && ChatStyles::isValidTimezone((string) $row['timezone']))
                ? (string) $row['timezone'] : $tzDefault,
            'notes'           => (is_string($row['notes'] ?? null) && $row['notes'] !== '') ? (string) $row['notes'] : null,
        ];
    }

    /** A stored font key, restricted to the whitelist; '' → null. */
    private function normalizeFont(string $key): ?string
    {
        foreach (ChatStyles::fontChoices() as $f) {
            if ($f['value'] === $key) {
                return $key;
            }
        }
        return null;
    }

    public function clampRefresh(int $v): int
    {
        return max($this->config->minRefreshSecs(), min($this->config->maxRefreshSecs(), $v));
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
        // sort_dir: null = follow the room default; else 0 (oldest first) or 1 (newest first).
        $sd = $sortDir === null ? null : ($sortDir === 0 ? 0 : 1);
        $tz = ($timezone !== null && ChatStyles::isValidTimezone($timezone)) ? $timezone : null;
        $no = ($notes !== null && trim($notes) !== '') ? mb_substr($notes, 0, 2000) : null;

        return $this->repo->save(
            $ident,
            $this->clampRefresh($refreshSecs),
            max(1, min(self::MSG_MAX, $messagesShown)),
            $showTimestamps,
            max(self::FONT_MIN, min(self::FONT_MAX, $fontSize)),
            $textColor,
            $linkConversion,
            $bgColor,
            $this->normalizeFont($fontFamily ?? ''),
            $sd,
            $hideChatters,
            $incognito,
            $tz,
            $no,
        );
    }

    /** @param array<string,mixed> $r */
    private function int(array $r, string $k, int $default): int
    {
        $v = $r[$k] ?? $default;
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);
    }
}
