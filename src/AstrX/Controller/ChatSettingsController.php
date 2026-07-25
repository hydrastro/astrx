<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatIdentity;
use AstrX\Chat\ChatNav;
use AstrX\Chat\ChatPresenceService;
use AstrX\Chat\ChatSettingsService;
use AstrX\Chat\ChatStyles;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Per-user chat display settings. Page row: WORDING_CHAT_SETTINGS, template=1.
 *
 * A single templated PRG form lets a participant tune how the chat renders for
 * them: refresh cadence, how many messages/PMs are shown, timestamp visibility,
 * font size, their text colour, and whether bare URLs become links. Values are
 * persisted per identity by ChatSettingsService (which clamps everything to safe
 * ranges), so the effective settings survive across sessions for members and for
 * the lifetime of a guest token.
 *
 * The page requires an identity (a logged-in member, or a guest who has already
 * chosen a nickname); without one it redirects to the chat login, exactly like
 * the shell. Submission follows the standard Post-Redirect-Get flow: verify the
 * CSRF token, save, then redirect back to the clean settings URL.
 */
final class ChatSettingsController extends AbstractController
{
    private const FORM = 'chat_settings';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ChatPresenceService    $presence,
        private readonly ChatSettingsService    $settings,
        private readonly ChatConfig             $config,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly ChatNav                $nav,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        // Settings belong to an identity; a guest without a nickname has none yet.
        $ident = $this->presence->identity();
        if ($ident === null) {
            Response::redirect($this->urlGen->toPage($this->t->t('WORDING_CHAT_LOGIN')))
                ->send()->drainTo($this->collector);
            exit;
        }

        // PRG replay: the settings form was posted, stored, and redirected here.
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($prgToken, $ident);
            Response::redirect($this->selfUrl())
                ->send()->drainTo($this->collector);
            exit;
        }

        return $this->renderForm($ident);
    }

    // -------------------------------------------------------------------------
    // POST handling
    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken, ChatIdentity $ident): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return;
        }

        // ChatSettingsService::save() clamps every value to a safe range, so the
        // raw posted numbers are handed over as-is; only the colour is validated
        // here (a non-matching value becomes null = "no override").
        $this->settings->save(
            $ident->ident,
            self::mInt($posted, 'refresh_secs', $this->config->defaultRefreshSecs()),
            self::mInt($posted, 'messages_shown', $this->config->messagesShown()),
            self::mBool($posted, 'show_timestamps'),
            self::mInt($posted, 'font_size', 16),
            $this->resolveColor($posted),
            self::mBool($posted, 'link_conversion'),
            $this->resolveBgColor($posted),
            $this->resolveFont($posted),
            $this->resolveSortDir($posted),
            self::mBool($posted, 'hide_chatters'),
            self::mBool($posted, 'incognito'),
            $this->resolveTimezone($posted),
            self::mStr($posted, 'notes', ''),
        )->drainTo($this->collector);
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** @return Result<mixed> */
    private function renderForm(ChatIdentity $ident): Result
    {
        $eff     = $this->settings->effective($ident->ident);
        $selfUrl = $this->selfUrl();

        // PRG + CSRF for the single form on this page.
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

        // Current effective values (form defaults).
        $this->ctx->set('refresh_secs',    $eff['refresh_secs']);
        $this->ctx->set('messages_shown',  $eff['messages_shown']);
        $this->ctx->set('show_timestamps', $eff['show_timestamps']);
        $this->ctx->set('font_size',       $eff['font_size']);
        $this->ctx->set('link_conversion', $eff['link_conversion']);

        // Colour: a whitelisted named dropdown + a custom #hex field. A stored
        // hex shows in the custom field; a stored name selects in the dropdown.
        $current      = is_string($eff['text_color'] ?? null) ? (string) $eff['text_color'] : '';
        $isHexCurrent = preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', $current) === 1;
        $options      = [];
        foreach (ChatStyles::palette() as $opt) {
            $options[] = [
                'value'    => $opt['value'],
                'label'    => $opt['label'],
                'selected' => !$isHexCurrent && strtolower($current) === $opt['value'],
            ];
        }
        $this->ctx->set('color_options',          $options);
        $this->ctx->set('color_default_selected', $current === '');
        $this->ctx->set('text_color_custom',      $isHexCurrent ? $current : '');

        // Background colour — same palette + custom-hex + random pattern as text.
        $bgCurrent = is_string($eff['bg_color'] ?? null) ? (string) $eff['bg_color'] : '';
        $bgIsHex   = preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', $bgCurrent) === 1;
        $bgOptions = [];
        foreach (ChatStyles::palette() as $opt) {
            $bgOptions[] = [
                'value'    => $opt['value'],
                'label'    => $opt['label'],
                'selected' => !$bgIsHex && strtolower($bgCurrent) === $opt['value'],
            ];
        }
        $this->ctx->set('bg_options',          $bgOptions);
        $this->ctx->set('bg_default_selected', $bgCurrent === '');
        $this->ctx->set('bg_color_custom',     $bgIsHex ? $bgCurrent : '');

        // Font family (whitelisted generic families).
        $curFont     = is_string($eff['font_family'] ?? null) ? (string) $eff['font_family'] : '';
        $fontOptions = [];
        foreach (ChatStyles::fontChoices() as $f) {
            $fontOptions[] = ['value' => $f['value'], 'label' => $f['label'], 'selected' => $curFont === $f['value']];
        }
        $this->ctx->set('font_options',          $fontOptions);
        $this->ctx->set('font_default_selected', $curFont === '');

        // Per-user message order (null = follow the room default).
        $sd = $eff['sort_dir'] ?? null;
        $this->ctx->set('sort_default_selected', $sd === null);
        $this->ctx->set('sort_newest_selected',  $sd === 1);
        $this->ctx->set('sort_oldest_selected',  $sd === 0);

        $this->ctx->set('hide_chatters', (bool) ($eff['hide_chatters'] ?? false));
        $this->ctx->set('incognito',     (bool) ($eff['incognito'] ?? false));

        // Timezone (curated IANA list) + personal notes.
        $curTz     = is_string($eff['timezone'] ?? null) ? (string) $eff['timezone'] : '';
        $tzOptions = [];
        foreach (ChatStyles::timezones() as $tz) {
            $tzOptions[] = ['value' => $tz, 'selected' => $curTz === $tz];
        }
        $this->ctx->set('tz_options',          $tzOptions);
        $this->ctx->set('tz_default_selected', $curTz === '');
        $this->ctx->set('notes',               is_string($eff['notes'] ?? null) ? (string) $eff['notes'] : '');

        // Read-only identity header.
        $this->ctx->set('posting_as_nick', $ident->nick);
        $this->ctx->set('is_member',       $ident->isMember);

        // Bounds for the refresh input (min/max come straight from the config).
        $this->ctx->set('min_refresh', $this->config->minRefreshSecs());
        $this->ctx->set('max_refresh', $this->config->maxRefreshSecs());

        // Back to the chat shell.
        $this->ctx->set('back_url', $this->urlGen->toPage($this->t->t('WORDING_CHAT')));

        // Labels.
        $this->ctx->set('settings_heading',      $this->t->t('chat.settings.heading'));
        $this->ctx->set('label_refresh_secs',    $this->t->t('chat.settings.refresh_secs'));
        $this->ctx->set('label_messages_shown',  $this->t->t('chat.settings.messages_shown'));
        $this->ctx->set('label_show_timestamps', $this->t->t('chat.settings.show_timestamps'));
        $this->ctx->set('label_font_size',       $this->t->t('chat.settings.font_size'));
        $this->ctx->set('label_text_color',      $this->t->t('chat.settings.text_color'));
        $this->ctx->set('color_default_label',   $this->t->t('chat.color_default'));
        $this->ctx->set('color_random_label',    $this->t->t('chat.color_random'));
        $this->ctx->set('color_custom_label',    $this->t->t('chat.color_custom'));
        $this->ctx->set('label_link_conversion', $this->t->t('chat.settings.link_conversion'));
        $this->ctx->set('label_submit',          $this->t->t('chat.settings.submit'));
        $this->ctx->set('label_back',            $this->t->t('chat.settings.back'));
        $this->ctx->set('label_bg_color',        $this->t->t('chat.settings.bg_color'));
        $this->ctx->set('label_font_family',     $this->t->t('chat.settings.font_family'));
        $this->ctx->set('label_sort_dir',        $this->t->t('chat.settings.sort_dir'));
        $this->ctx->set('label_hide_chatters',   $this->t->t('chat.settings.hide_chatters'));
        $this->ctx->set('label_incognito',       $this->t->t('chat.settings.incognito'));
        $this->ctx->set('opt_font_default',      $this->t->t('chat.settings.font_default'));
        $this->ctx->set('opt_sort_default',      $this->t->t('chat.settings.sort_default'));
        $this->ctx->set('opt_sort_newest',       $this->t->t('chat.settings.sort_newest'));
        $this->ctx->set('opt_sort_oldest',       $this->t->t('chat.settings.sort_oldest'));
        $this->ctx->set('section_display',       $this->t->t('chat.settings.section_display'));
        $this->ctx->set('section_colours',       $this->t->t('chat.settings.section_colours'));
        $this->ctx->set('section_privacy',       $this->t->t('chat.settings.section_privacy'));
        $this->ctx->set('section_notes',         $this->t->t('chat.settings.section_notes'));
        $this->ctx->set('label_timezone',        $this->t->t('chat.settings.timezone'));
        $this->ctx->set('opt_tz_default',        $this->t->t('chat.settings.tz_default'));
        $this->ctx->set('label_notes',           $this->t->t('chat.settings.notes'));
        $this->ctx->set('posting_as_label',      $this->t->t('chat.posting_as'));
        $this->ctx->set('guest_tag',             $this->t->t('chat.guest_tag'));

        // Chat toolbar (this is the Profile page).
        $this->nav->apply($this->ctx, 'profile');

        return $this->ok();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the chosen colour: a valid custom #hex wins, otherwise the named
     * palette selection (whitelisted). Null means "no override".
     *
     * @param array<string,mixed> $posted
     */
    private function resolveColor(array $posted): ?string
    {
        $custom = trim(self::mStr($posted, 'text_color_custom', ''));
        if ($custom !== '' && preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', $custom) === 1) {
            return strtolower($custom);
        }
        $named = strtolower(trim(self::mStr($posted, 'text_color', '')));
        if ($named === 'random') {
            return ChatStyles::randomColor();
        }
        foreach (ChatStyles::palette() as $opt) {
            if ($opt['value'] === $named) {
                return $named;
            }
        }
        return null;
    }

    /**
     * Personal background colour — custom #hex wins, else a whitelisted palette
     * name, else 'random' → a random palette colour, else null (no override).
     *
     * @param array<string,mixed> $posted
     */
    private function resolveBgColor(array $posted): ?string
    {
        $custom = trim(self::mStr($posted, 'bg_color_custom', ''));
        if ($custom !== '' && preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{6}$/', $custom) === 1) {
            return strtolower($custom);
        }
        $named = strtolower(trim(self::mStr($posted, 'bg_color', '')));
        if ($named === 'random') {
            return ChatStyles::randomColor();
        }
        foreach (ChatStyles::palette() as $opt) {
            if ($opt['value'] === $named) {
                return $named;
            }
        }
        return null;
    }

    /**
     * Posted font key (the service whitelists it); '' → null.
     *
     * @param array<string,mixed> $posted
     */
    private function resolveFont(array $posted): ?string
    {
        $f = strtolower(trim(self::mStr($posted, 'font_family', '')));
        return $f !== '' ? $f : null;
    }

    /**
     * Per-user order: '1' newest, '0' oldest, anything else → null (room default).
     *
     * @param array<string,mixed> $posted
     */
    private function resolveSortDir(array $posted): ?int
    {
        return match (self::mStr($posted, 'sort_dir', '')) {
            '1'     => 1,
            '0'     => 0,
            default => null,
        };
    }

    /**
     * Posted timezone; the service validates it against the offered list.
     *
     * @param array<string,mixed> $posted
     */
    private function resolveTimezone(array $posted): ?string
    {
        $tz = trim(self::mStr($posted, 'timezone', ''));
        return $tz !== '' ? $tz : null;
    }

    private function selfUrl(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_CHAT_SETTINGS'));
    }
}
