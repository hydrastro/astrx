<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\ChatConfig;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin — Chat configuration editor.
 *
 * Edits the single 'ChatConfig' section of Chat.config.php through ConfigWriter,
 * mirroring the other admin config editors (captcha, mail, system): gate on the
 * page permission, PRG+CSRF on submit, then a self-redirect; on GET the form is
 * populated from the current configuration.
 *
 * Injecting {@see ChatConfig} forces the 'ChatConfig' config domain to load (the
 * module loader applies Chat.config.php's 'ChatConfig' section to it at
 * construction), so the current values can be read straight off its typed
 * getters — the cleanest, level-10-safe way to populate the form.
 *
 * Because Chat.config.php contains exactly one section, a save simply rewrites
 * the whole file with the full, freshly-built 'ChatConfig' array.
 */
final class AdminConfigChatController extends AbstractController
{
    private const FORM = 'admin_config_chat';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ChatConfig             $chatConfig,
        private readonly ConfigWriter           $writer,
        private readonly Gate                   $gate,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly Page                   $page,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly AuditLogger            $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_CHAT)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl);
        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $result = $this->writer->write('Chat', ['ChatConfig' => $this->sectionFrom($posted)]);
        $result->drainTo($this->collector);
        if ($result->isOk()) {
            $this->flash->set('success', $this->t->t('admin.config.saved'));
            $this->audit->log('config.save', 'Chat.config.php')->drainTo($this->collector);
        }
    }

    /**
     * Build the full 'ChatConfig' section from the posted form. Every key of the
     * section is read here so the write replaces the file completely.
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function sectionFrom(array $p): array
    {
        // R11 (HIGH image_embed / MED upload_dir): both are SYSTEM-level. With
        // image_embed ON, BbcodeRenderer turns any posted image URL into an <img>
        // that EVERY viewer's browser auto-fetches — an IP-correlation / de-
        // anonymization channel against all chat participants on a hidden service
        // (it defaults OFF for exactly this "TOR privacy" reason). upload_dir
        // repoints where attachments are written and served, potentially outside
        // the CHAT_VIEW-gated, Tor-hardened file controller. The page gate is only
        // ADMIN_CONFIG_CHAT (a MOD may hold it); these two are admin-only, so a
        // non-system actor's save PRESERVES the current on-disk values (R10 pattern).
        $maySetSystem = $this->gate->can(Permission::ADMIN_CONFIG_SYSTEM);
        return [
            // ── Access ──────────────────────────────────────────────────────
            'guest_posting'           => self::mBool($p, 'guest_posting'),
            'guest_captcha'           => self::mBool($p, 'guest_captcha'),
            'require_login_to_read'   => self::mBool($p, 'require_login_to_read'),
            'entry_password'          => self::mStr($p, 'entry_password', ''),
            'chat_enabled'            => self::mBool($p, 'chat_enabled'),
            'disabled_message'        => self::mStr($p, 'disabled_message', ''),
            'guest_access_mode'       => self::mStr($p, 'guest_access_mode', 'waiting_room'),
            'approval_fallback_waiting' => self::mBool($p, 'approval_fallback_waiting'),
            // ── Waiting room ────────────────────────────────────────────────
            'waiting_room_seconds'    => self::mInt($p, 'waiting_room_seconds', 10),
            'waiting_room_mandatory'  => self::mBool($p, 'waiting_room_mandatory'),
            // ── Messages ────────────────────────────────────────────────────
            'max_length'              => self::mInt($p, 'max_length', 2000),
            'messages_shown'          => self::mInt($p, 'messages_shown', 50),
            'retention_minutes'       => self::mInt($p, 'retention_minutes', 1440),
            'newest_first'            => self::mBool($p, 'newest_first'),
            'bbcode_enabled'          => self::mBool($p, 'bbcode_enabled'),
            'link_conversion'         => self::mBool($p, 'link_conversion'),
            'announce_join_leave'     => self::mBool($p, 'announce_join_leave'),
            'announce_mod_actions'    => self::mBool($p, 'announce_mod_actions'),
            'image_embed'             => $maySetSystem ? self::mBool($p, 'image_embed') : $this->chatConfig->imageEmbed(),
            // ── Rate limiting / flood ───────────────────────────────────────
            'min_flood_secs'          => self::mInt($p, 'min_flood_secs', 3),
            'flood_mute_secs'         => self::mInt($p, 'flood_mute_secs', 30),
            // ── Refresh / presence ──────────────────────────────────────────
            'default_refresh_secs'    => self::mInt($p, 'default_refresh_secs', 5),
            'min_refresh_secs'        => self::mInt($p, 'min_refresh_secs', 2),
            'max_refresh_secs'        => self::mInt($p, 'max_refresh_secs', 60),
            'online_window_secs'      => self::mInt($p, 'online_window_secs', 45),
            // ── Nicknames ───────────────────────────────────────────────────
            'nick_min_len'            => self::mInt($p, 'nick_min_len', 2),
            'nick_max_len'            => self::mInt($p, 'nick_max_len', 32),
            'names_link_to_profile'   => self::mBool($p, 'names_link_to_profile'),
            'nick_regex'              => self::mStr($p, 'nick_regex', ''),
            // ── Colours ─────────────────────────────────────────────────────
            'allow_user_color'        => self::mBool($p, 'allow_user_color'),
            'default_color'           => trim(self::mStr($p, 'default_color', '')),
            'default_bg_color'        => trim(self::mStr($p, 'default_bg_color', '')),
            'default_font_family'     => trim(self::mStr($p, 'default_font_family', '')),
            // ── Private messages ────────────────────────────────────────────
            'allow_pm'                => self::mBool($p, 'allow_pm'),
            'pm_retention_minutes'    => self::mInt($p, 'pm_retention_minutes', 1440),
            'disable_guest_pm'        => self::mBool($p, 'disable_guest_pm'),
            // ── Word censor ─────────────────────────────────────────────────
            'censor_words'            => self::parseCensorWords(self::mStr($p, 'censor_words', '')),
            'censor_mode'             => self::mStr($p, 'censor_mode', 'replace') === 'block' ? 'block' : 'replace',
            'censor_replacement'      => self::mStr($p, 'censor_replacement', '***'),
            // ── Room ────────────────────────────────────────────────────────
            'room_topic'              => self::mStr($p, 'room_topic', ''),
            'room_rules'              => self::mStr($p, 'room_rules', ''),
            'max_online'              => self::mInt($p, 'max_online', 0),
            'chat_name'               => self::mStr($p, 'chat_name', ''),
            'greeting_message'        => self::mStr($p, 'greeting_message', ''),
            // ── Timestamps ──────────────────────────────────────────────────
            'show_timestamps_default' => self::mBool($p, 'show_timestamps_default'),
            'timestamp_format'        => self::mStr($p, 'timestamp_format', 'H:i'),
            'default_timezone'        => trim(self::mStr($p, 'default_timezone', '')),
            // ── Moderation ──────────────────────────────────────────────────
            'kick_penalty_minutes'    => self::mInt($p, 'kick_penalty_minutes', 10),
            // ── Toolbar ─────────────────────────────────────────────────────
            'hide_help_button'        => self::mBool($p, 'hide_help_button'),
            'hide_profile_button'     => self::mBool($p, 'hide_profile_button'),
            'hide_notes_button'       => self::mBool($p, 'hide_notes_button'),
            'hide_rules_button'       => self::mBool($p, 'hide_rules_button'),
            'hide_admin_button'       => self::mBool($p, 'hide_admin_button'),
            'hide_clone_button'       => self::mBool($p, 'hide_clone_button'),
            'hide_reload_button'      => self::mBool($p, 'hide_reload_button'),
            'hide_rearrange_button'   => self::mBool($p, 'hide_rearrange_button'),
            // ── Attachments (Phase 5) ───────────────────────────────────────
            'uploads_enabled'         => self::mBool($p, 'uploads_enabled'),
            'uploads_guests'          => self::mBool($p, 'uploads_guests'),
            'upload_max_kb'           => self::mInt($p, 'upload_max_kb', 2048),
            'upload_max_dimension'    => self::mInt($p, 'upload_max_dimension', 1600),
            'upload_types'            => self::mStr($p, 'upload_types', 'jpg,jpeg,png,gif,webp'),
            'upload_dir'              => $maySetSystem ? self::mStr($p, 'upload_dir', '') : $this->chatConfig->uploadDirRaw(),
        ];
    }

    /**
     * Split a textarea (one term per line) into a trimmed, non-empty list.
     *
     * @return list<string>
     */
    private static function parseCensorWords(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }

    // ── Context builder ─────────────────────────────────────────────────────

    private function buildContext(string $selfUrl): void
    {
        $c = $this->chatConfig;

        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));

        // ── Current values: booleans (checkbox sections) ─────────────────────
        $this->ctx->set('cfg_guest_posting',           $c->guestPosting());
        $this->ctx->set('cfg_guest_captcha',           $c->guestCaptcha());
        $this->ctx->set('cfg_require_login_to_read',   $c->requireLoginToRead());
        $this->ctx->set('cfg_chat_enabled',            $c->chatEnabled());
        $this->ctx->set('cfg_approval_fallback_waiting', $c->approvalFallbackWaiting());
        $this->ctx->set('cfg_announce_join_leave',     $c->announceJoinLeave());
        $this->ctx->set('cfg_announce_mod_actions',    $c->announceModActions());
        $this->ctx->set('cfg_image_embed',             $c->imageEmbed());
        $this->ctx->set('cfg_entry_password',          $c->entryPassword());
        $this->ctx->set('cfg_disabled_message',        $c->disabledMessage());
        $this->ctx->set('cfg_newest_first',            $c->newestFirst());
        $this->ctx->set('cfg_bbcode_enabled',          $c->bbcodeEnabled());
        $this->ctx->set('cfg_link_conversion',         $c->linkConversion());
        $this->ctx->set('cfg_names_link_to_profile',   $c->namesLinkToProfile());
        $this->ctx->set('cfg_allow_user_color',        $c->allowUserColor());
        $this->ctx->set('cfg_allow_pm',                $c->allowPm());
        $this->ctx->set('cfg_show_timestamps_default', $c->showTimestampsDefault());
        $this->ctx->set('cfg_disable_guest_pm',        $c->disableGuestPm());
        $this->ctx->set('cfg_hide_help_button',        $c->hideHelpButton());
        $this->ctx->set('cfg_hide_profile_button',     $c->hideProfileButton());
        $this->ctx->set('cfg_hide_notes_button',       $c->hideNotesButton());
        $this->ctx->set('cfg_hide_rules_button',       $c->hideRulesButton());
        $this->ctx->set('cfg_hide_admin_button',       $c->hideAdminButton());
        $this->ctx->set('cfg_hide_clone_button',       $c->hideCloneButton());
        $this->ctx->set('cfg_hide_reload_button',      $c->hideReloadButton());
        $this->ctx->set('cfg_hide_rearrange_button',   $c->hideRearrangeButton());

        // ── Current values: integers (number inputs) ─────────────────────────
        $this->ctx->set('cfg_waiting_room_seconds', $c->waitingRoomSeconds());
        $this->ctx->set('cfg_waiting_room_mandatory', $c->waitingRoomMandatory());
        $this->ctx->set('cfg_max_length',           $c->maxLength());
        $this->ctx->set('cfg_messages_shown',       $c->messagesShown());
        $this->ctx->set('cfg_retention_minutes',    $c->retentionMinutes());
        $this->ctx->set('cfg_min_flood_secs',       $c->minFloodSecs());
        $this->ctx->set('cfg_flood_mute_secs',      $c->floodMuteSecs());
        $this->ctx->set('cfg_default_refresh_secs', $c->defaultRefreshSecs());
        $this->ctx->set('cfg_min_refresh_secs',     $c->minRefreshSecs());
        $this->ctx->set('cfg_max_refresh_secs',     $c->maxRefreshSecs());
        $this->ctx->set('cfg_online_window_secs',   $c->onlineWindowSecs());
        $this->ctx->set('cfg_nick_min_len',         $c->nickMinLen());
        $this->ctx->set('cfg_nick_max_len',         $c->nickMaxLen());
        $this->ctx->set('cfg_pm_retention_minutes', $c->pmRetentionMinutes());
        $this->ctx->set('cfg_max_online',           $c->maxOnline());
        $this->ctx->set('cfg_kick_penalty_minutes', $c->kickPenaltyMinutes());

        // ── Current values: strings (text inputs) ────────────────────────────
        $this->ctx->set('cfg_default_color',      $c->defaultColor());
        $this->ctx->set('cfg_censor_replacement', $c->censorReplacement());
        $this->ctx->set('cfg_room_topic',         $c->roomTopic());
        $this->ctx->set('cfg_room_rules',         $c->roomRules());
        $this->ctx->set('cfg_timestamp_format',   $c->timestampFormat());
        $this->ctx->set('cfg_nick_regex',          $c->nickRegex());
        $this->ctx->set('cfg_default_bg_color',    $c->defaultBgColor());
        $this->ctx->set('cfg_default_font_family', $c->defaultFontFamily());
        $this->ctx->set('cfg_default_timezone',    $c->defaultTimezone());
        $this->ctx->set('cfg_chat_name',           $c->chatName());
        $this->ctx->set('cfg_greeting_message',    $c->greetingMessage());

        // censor_words → newline-joined text for the textarea
        $this->ctx->set('cfg_censor_words', implode("\n", $c->censorWords()));

        // censor_mode → per-option selected flags
        $mode = $c->censorMode();
        $this->ctx->set('cfg_censor_mode_replace_selected', $mode !== 'block');
        $this->ctx->set('cfg_censor_mode_block_selected',   $mode === 'block');

        // guest_access_mode → per-option selected flags for the dropdown
        $gm = $c->guestAccessMode();
        $this->ctx->set('cfg_access_open_selected',     $gm === ChatConfig::ACCESS_OPEN);
        $this->ctx->set('cfg_access_waiting_selected',  $gm === ChatConfig::ACCESS_WAITING);
        $this->ctx->set('cfg_access_approval_selected', $gm === ChatConfig::ACCESS_APPROVAL);
        $this->ctx->set('cfg_access_members_selected',  $gm === ChatConfig::ACCESS_MEMBERS_ONLY);

        // ── Attachments (Phase 5) ────────────────────────────────────────────
        $this->ctx->set('cfg_uploads_enabled',      $c->uploadsEnabled());
        $this->ctx->set('cfg_uploads_guests',       $c->uploadsGuests());
        $this->ctx->set('cfg_upload_max_kb',        $c->uploadMaxKb());
        $this->ctx->set('cfg_upload_max_dimension', $c->uploadMaxDimension());
        $this->ctx->set('cfg_upload_types',         $c->uploadTypesRaw());
        $this->ctx->set('cfg_upload_dir',           $c->uploadDir());

        $this->setI18n();
    }

    private function setI18n(): void
    {
        $this->ctx->set('heading', $this->t->t('admin.config.chat.heading'));

        // ── Section headings ─────────────────────────────────────────────────
        $this->ctx->set('section_access',     $this->t->t('admin.config.chat.section_access'));
        $this->ctx->set('section_waiting',    $this->t->t('admin.config.chat.section_waiting'));
        $this->ctx->set('section_messages',   $this->t->t('admin.config.chat.section_messages'));
        $this->ctx->set('section_flood',      $this->t->t('admin.config.chat.section_flood'));
        $this->ctx->set('section_refresh',    $this->t->t('admin.config.chat.section_refresh'));
        $this->ctx->set('section_nicknames',  $this->t->t('admin.config.chat.section_nicknames'));
        $this->ctx->set('section_colors',     $this->t->t('admin.config.chat.section_colors'));
        $this->ctx->set('section_pm',         $this->t->t('admin.config.chat.section_pm'));
        $this->ctx->set('section_censor',     $this->t->t('admin.config.chat.section_censor'));
        $this->ctx->set('chat_filters_url',   $this->urlGen->toPage($this->t->t('WORDING_ADMIN_CHAT_FILTERS')));
        $this->ctx->set('chat_filters_link',  $this->t->t('admin.config.chat.filters_link'));
        $this->ctx->set('section_uploads',    $this->t->t('admin.config.chat.section_uploads'));
        $this->ctx->set('chat_console_url',   $this->urlGen->toPage($this->t->t('WORDING_CHAT_ADMIN')));
        $this->ctx->set('chat_console_link',  $this->t->t('admin.config.chat.console_link'));
        $this->ctx->set('section_room',       $this->t->t('admin.config.chat.section_room'));
        $this->ctx->set('section_timestamps', $this->t->t('admin.config.chat.section_timestamps'));
        $this->ctx->set('section_moderation', $this->t->t('admin.config.chat.section_moderation'));
        $this->ctx->set('section_toolbar',    $this->t->t('admin.config.chat.section_toolbar'));

        // ── Field labels ─────────────────────────────────────────────────────
        $this->ctx->set('label_guest_posting',           $this->t->t('admin.config.chat.field.guest_posting'));
        $this->ctx->set('label_guest_captcha',           $this->t->t('admin.config.chat.field.guest_captcha'));
        $this->ctx->set('label_require_login_to_read',   $this->t->t('admin.config.chat.field.require_login_to_read'));
        $this->ctx->set('label_entry_password',          $this->t->t('admin.config.chat.field.entry_password'));
        $this->ctx->set('label_chat_enabled',            $this->t->t('admin.config.chat.field.chat_enabled'));
        $this->ctx->set('label_disabled_message',        $this->t->t('admin.config.chat.field.disabled_message'));
        $this->ctx->set('label_guest_access_mode',       $this->t->t('admin.config.chat.field.guest_access_mode'));
        $this->ctx->set('label_approval_fallback_waiting', $this->t->t('admin.config.chat.field.approval_fallback_waiting'));
        $this->ctx->set('label_announce_join_leave',     $this->t->t('admin.config.chat.field.announce_join_leave'));
        $this->ctx->set('label_announce_mod_actions',    $this->t->t('admin.config.chat.field.announce_mod_actions'));
        $this->ctx->set('label_image_embed',             $this->t->t('admin.config.chat.field.image_embed'));
        $this->ctx->set('label_waiting_room_seconds',    $this->t->t('admin.config.chat.field.waiting_room_seconds'));
        $this->ctx->set('label_waiting_room_mandatory',  $this->t->t('admin.config.chat.field.waiting_room_mandatory'));
        $this->ctx->set('label_max_length',              $this->t->t('admin.config.chat.field.max_length'));
        $this->ctx->set('label_messages_shown',          $this->t->t('admin.config.chat.field.messages_shown'));
        $this->ctx->set('label_retention_minutes',       $this->t->t('admin.config.chat.field.retention_minutes'));
        $this->ctx->set('label_newest_first',            $this->t->t('admin.config.chat.field.newest_first'));
        $this->ctx->set('label_bbcode_enabled',          $this->t->t('admin.config.chat.field.bbcode_enabled'));
        $this->ctx->set('label_link_conversion',         $this->t->t('admin.config.chat.field.link_conversion'));
        $this->ctx->set('label_min_flood_secs',          $this->t->t('admin.config.chat.field.min_flood_secs'));
        $this->ctx->set('label_flood_mute_secs',         $this->t->t('admin.config.chat.field.flood_mute_secs'));
        $this->ctx->set('label_default_refresh_secs',    $this->t->t('admin.config.chat.field.default_refresh_secs'));
        $this->ctx->set('label_min_refresh_secs',        $this->t->t('admin.config.chat.field.min_refresh_secs'));
        $this->ctx->set('label_max_refresh_secs',        $this->t->t('admin.config.chat.field.max_refresh_secs'));
        $this->ctx->set('label_online_window_secs',      $this->t->t('admin.config.chat.field.online_window_secs'));
        $this->ctx->set('label_nick_min_len',            $this->t->t('admin.config.chat.field.nick_min_len'));
        $this->ctx->set('label_nick_max_len',            $this->t->t('admin.config.chat.field.nick_max_len'));
        $this->ctx->set('label_names_link_to_profile',   $this->t->t('admin.config.chat.field.names_link_to_profile'));
        $this->ctx->set('label_allow_user_color',        $this->t->t('admin.config.chat.field.allow_user_color'));
        $this->ctx->set('label_default_color',           $this->t->t('admin.config.chat.field.default_color'));
        $this->ctx->set('label_allow_pm',                $this->t->t('admin.config.chat.field.allow_pm'));
        $this->ctx->set('label_pm_retention_minutes',    $this->t->t('admin.config.chat.field.pm_retention_minutes'));
        $this->ctx->set('label_censor_words',            $this->t->t('admin.config.chat.field.censor_words'));
        $this->ctx->set('label_censor_mode',             $this->t->t('admin.config.chat.field.censor_mode'));
        $this->ctx->set('label_censor_replacement',      $this->t->t('admin.config.chat.field.censor_replacement'));
        $this->ctx->set('label_room_topic',              $this->t->t('admin.config.chat.field.room_topic'));
        $this->ctx->set('label_room_rules',              $this->t->t('admin.config.chat.field.room_rules'));
        $this->ctx->set('label_max_online',              $this->t->t('admin.config.chat.field.max_online'));
        $this->ctx->set('label_show_timestamps_default', $this->t->t('admin.config.chat.field.show_timestamps_default'));
        $this->ctx->set('label_timestamp_format',        $this->t->t('admin.config.chat.field.timestamp_format'));
        $this->ctx->set('label_chat_name',               $this->t->t('admin.config.chat.field.chat_name'));
        $this->ctx->set('label_greeting_message',        $this->t->t('admin.config.chat.field.greeting_message'));
        $this->ctx->set('label_nick_regex',              $this->t->t('admin.config.chat.field.nick_regex'));
        $this->ctx->set('label_default_bg_color',        $this->t->t('admin.config.chat.field.default_bg_color'));
        $this->ctx->set('label_default_font_family',     $this->t->t('admin.config.chat.field.default_font_family'));
        $this->ctx->set('label_default_timezone',        $this->t->t('admin.config.chat.field.default_timezone'));
        $this->ctx->set('label_disable_guest_pm',        $this->t->t('admin.config.chat.field.disable_guest_pm'));
        $this->ctx->set('label_kick_penalty_minutes',    $this->t->t('admin.config.chat.field.kick_penalty_minutes'));
        $this->ctx->set('label_uploads_enabled',         $this->t->t('admin.config.chat.field.uploads_enabled'));
        $this->ctx->set('label_uploads_guests',          $this->t->t('admin.config.chat.field.uploads_guests'));
        $this->ctx->set('label_upload_max_kb',           $this->t->t('admin.config.chat.field.upload_max_kb'));
        $this->ctx->set('label_upload_max_dimension',    $this->t->t('admin.config.chat.field.upload_max_dimension'));
        $this->ctx->set('label_upload_types',            $this->t->t('admin.config.chat.field.upload_types'));
        $this->ctx->set('label_upload_dir',              $this->t->t('admin.config.chat.field.upload_dir'));
        $this->ctx->set('label_hide_help_button',        $this->t->t('admin.config.chat.field.hide_help_button'));
        $this->ctx->set('label_hide_profile_button',     $this->t->t('admin.config.chat.field.hide_profile_button'));
        $this->ctx->set('label_hide_notes_button',       $this->t->t('admin.config.chat.field.hide_notes_button'));
        $this->ctx->set('label_hide_rules_button',       $this->t->t('admin.config.chat.field.hide_rules_button'));
        $this->ctx->set('label_hide_admin_button',       $this->t->t('admin.config.chat.field.hide_admin_button'));
        $this->ctx->set('label_hide_clone_button',       $this->t->t('admin.config.chat.field.hide_clone_button'));
        $this->ctx->set('label_hide_reload_button',      $this->t->t('admin.config.chat.field.hide_reload_button'));
        $this->ctx->set('label_hide_rearrange_button',   $this->t->t('admin.config.chat.field.hide_rearrange_button'));

        // ── censor_mode option labels ────────────────────────────────────────
        $this->ctx->set('label_censor_mode_replace', $this->t->t('admin.config.chat.censor_mode_replace'));
        $this->ctx->set('label_censor_mode_block',   $this->t->t('admin.config.chat.censor_mode_block'));

        // guest_access_mode option labels
        $this->ctx->set('label_access_open',     $this->t->t('admin.config.chat.access_open'));
        $this->ctx->set('label_access_waiting',  $this->t->t('admin.config.chat.access_waiting'));
        $this->ctx->set('label_access_approval', $this->t->t('admin.config.chat.access_approval'));
        $this->ctx->set('label_access_members',  $this->t->t('admin.config.chat.access_members'));

        $this->ctx->set('btn_save', $this->t->t('admin.btn.save'));
    }
}
