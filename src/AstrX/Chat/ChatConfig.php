<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Config\InjectConfig;

/**
 * Central, admin-editable chat configuration.
 *
 * Every setting the chat exposes lives here as one typed property with an
 * #[InjectConfig] setter (bound to the matching key in Chat.config.php's
 * 'ChatConfig' section) and a getter. Because it is a single injected holder,
 * every chat service/controller reads settings the same way, and the admin
 * config editor edits exactly one section through ConfigWriter.
 *
 * Setters clamp to safe ranges so a bad edit can never produce a zero refresh
 * interval, a negative length cap, etc.
 */
final class ChatConfig
{
    /** Guest-access modes (le-chat "guestaccess") — the single entry policy. */
    public const ACCESS_OPEN         = 'open';               // guests enter freely
    public const ACCESS_WAITING      = 'waiting_room';       // guests wait the timed room
    public const ACCESS_APPROVAL     = 'moderator_approval'; // guests queue for a moderator
    public const ACCESS_MEMBERS_ONLY = 'members_only';       // guests are turned away

    // ── Access ────────────────────────────────────────────────────────────
    private bool   $guestPosting       = true;
    private bool   $guestCaptcha       = true;
    private bool   $stripExif          = true;   // re-encode attachments to strip EXIF/metadata (opt-out)
    private bool   $requireLoginToRead = false;
    private string $entryPassword      = '';
    private bool   $chatEnabled        = true;
    private string $disabledMessage    = '';
    private string $guestAccessMode        = self::ACCESS_WAITING; // subsumes members-only + the waiting room
    private bool   $approvalFallbackWaiting = true;                // fall back to the timed room when no mod is online
    // ── Waiting room ──────────────────────────────────────────────────────
    private int  $waitingRoomSeconds   = 10;
    private bool $waitingRoomMandatory = false;
    // ── Messages ──────────────────────────────────────────────────────────
    private int  $maxLength           = 2000;
    private int  $messagesShown       = 50;
    private int  $retentionMinutes    = 1440;
    private bool $newestFirst         = true;
    private bool $bbcodeEnabled       = true;
    private bool $linkConversion      = true;
    private bool $announceJoinLeave   = true;
    private bool $announceModActions  = true;
    private bool $imageEmbed          = false;
    // ── Rate / flood ──────────────────────────────────────────────────────
    private int  $minFloodSecs        = 3;
    private int  $floodMuteSecs       = 30;
    // ── Refresh / presence ────────────────────────────────────────────────
    private int  $defaultRefreshSecs  = 5;
    private int  $minRefreshSecs      = 2;
    private int  $maxRefreshSecs      = 60;
    private int  $onlineWindowSecs    = 45;
    // ── Nicknames ─────────────────────────────────────────────────────────
    private int  $nickMinLen          = 2;
    private int  $nickMaxLen          = 32;
    private bool $namesLinkToProfile  = true;
    // ── Colours ───────────────────────────────────────────────────────────
    private bool   $allowUserColor    = true;
    private string $defaultColor      = '';
    // ── Private messages ──────────────────────────────────────────────────
    private bool $allowPm             = true;
    private int  $pmRetentionMinutes  = 1440;
    // ── Word censor ───────────────────────────────────────────────────────
    /** @var list<string> */
    private array  $censorWords       = [];
    private string $censorMode        = 'replace';
    private string $censorReplacement = '***';
    // ── Room ──────────────────────────────────────────────────────────────
    private string $roomTopic         = '';
    private string $roomRules         = '';
    private int    $maxOnline         = 0;
    // ── Timestamps ────────────────────────────────────────────────────────
    private bool   $showTimestampsDefault = true;
    private string $timestampFormat       = 'H:i';
    // ── le-chat parity: branding / global defaults / moderation / toolbar ──
    private string $chatName            = '';     // heading override ('' = i18n default)
    private string $greetingMessage     = '';     // MOTD shown above the stream ('' = none)
    private string $defaultBgColor      = '';     // global frame background when a user hasn't set one
    private string $defaultFontFamily   = '';     // global default font key (whitelisted)
    private string $defaultTimezone     = '';     // global fallback IANA zone for timestamps
    private bool   $disableGuestPm      = false;  // guests may not send PMs (members still can)
    private int    $kickPenaltyMinutes  = 10;     // temporary re-entry ban applied on kick (0 = off)
    private string $nickRegex           = '';     // custom nickname regex ('' = built-in length rule)
    private bool   $hideHelpButton      = false;  // hide the Help item in the chat toolbar
    private bool   $hideProfileButton   = false;  // hide the Profile item in the chat toolbar
    private bool   $hideNotesButton     = false;  // hide the Notes toolbar item
    private bool   $hideRulesButton     = false;  // hide the Rules toolbar item
    private bool   $hideAdminButton     = false;  // hide the Admin toolbar item (staff only anyway)
    private bool   $hideCloneButton     = false;  // hide the "open in new window" toolbar item
    private bool   $hideReloadButton    = false;  // hide the shell reload controls
    private bool   $hideRearrangeButton = false;  // hide the shell rearrange-layout control

    // ── Phase 5: image attachments ────────────────────────────────────────────
    private bool   $uploadsEnabled     = false;  // allow image attachments in posts
    private bool   $uploadsGuests      = false;  // guests may attach (members may whenever enabled)
    private int    $uploadMaxKb        = 2048;   // hard per-file size cap (KB)
    private int    $uploadMaxDimension = 1600;   // downscale over this many px on the longest side (0 = off)
    private string $uploadTypesRaw     = 'jpg,jpeg,png,gif,webp'; // accepted extensions (comma list)
    private string $uploadDir          = '';     // absolute storage directory for stored images

    // =========================================================================
    // Setters (config injection)
    // =========================================================================

    #[InjectConfig('guest_posting')]         public function setGuestPosting(bool $v): void        { $this->guestPosting = $v; }
    #[InjectConfig('guest_captcha')]         public function setGuestCaptcha(bool $v): void        { $this->guestCaptcha = $v; }
    #[InjectConfig('strip_exif')]            public function setStripExif(bool $v): void           { $this->stripExif = $v; }
    #[InjectConfig('require_login_to_read')] public function setRequireLoginToRead(bool $v): void  { $this->requireLoginToRead = $v; }
    #[InjectConfig('entry_password')]        public function setEntryPassword(string $v): void    { $this->entryPassword = trim($v); }
    #[InjectConfig('chat_enabled')]          public function setChatEnabled(bool $v): void         { $this->chatEnabled = $v; }
    #[InjectConfig('disabled_message')]      public function setDisabledMessage(string $v): void   { $this->disabledMessage = $v; }
    #[InjectConfig('guest_access_mode')]     public function setGuestAccessMode(string $v): void   { $this->guestAccessMode = in_array($v, [self::ACCESS_OPEN, self::ACCESS_WAITING, self::ACCESS_APPROVAL, self::ACCESS_MEMBERS_ONLY], true) ? $v : self::ACCESS_WAITING; }
    #[InjectConfig('approval_fallback_waiting')] public function setApprovalFallbackWaiting(bool $v): void { $this->approvalFallbackWaiting = $v; }
    #[InjectConfig('announce_join_leave')]   public function setAnnounceJoinLeave(bool $v): void   { $this->announceJoinLeave = $v; }
    #[InjectConfig('announce_mod_actions')]  public function setAnnounceModActions(bool $v): void  { $this->announceModActions = $v; }
    #[InjectConfig('image_embed')]           public function setImageEmbed(bool $v): void          { $this->imageEmbed = $v; }
    #[InjectConfig('waiting_room_seconds')]  public function setWaitingRoomSeconds(int $v): void   { $this->waitingRoomSeconds = max(0, $v); }
    #[InjectConfig('waiting_room_mandatory')] public function setWaitingRoomMandatory(bool $v): void { $this->waitingRoomMandatory = $v; }
    #[InjectConfig('max_length')]            public function setMaxLength(int $v): void            { $this->maxLength = max(1, $v); }
    #[InjectConfig('messages_shown')]        public function setMessagesShown(int $v): void        { $this->messagesShown = max(1, $v); }
    #[InjectConfig('retention_minutes')]     public function setRetentionMinutes(int $v): void     { $this->retentionMinutes = max(1, $v); }
    #[InjectConfig('newest_first')]          public function setNewestFirst(bool $v): void         { $this->newestFirst = $v; }
    #[InjectConfig('bbcode_enabled')]        public function setBbcodeEnabled(bool $v): void       { $this->bbcodeEnabled = $v; }
    #[InjectConfig('link_conversion')]       public function setLinkConversion(bool $v): void      { $this->linkConversion = $v; }
    #[InjectConfig('min_flood_secs')]        public function setMinFloodSecs(int $v): void         { $this->minFloodSecs = max(0, $v); }
    #[InjectConfig('flood_mute_secs')]       public function setFloodMuteSecs(int $v): void        { $this->floodMuteSecs = max(0, $v); }
    #[InjectConfig('default_refresh_secs')]  public function setDefaultRefreshSecs(int $v): void   { $this->defaultRefreshSecs = max(1, $v); }
    #[InjectConfig('min_refresh_secs')]      public function setMinRefreshSecs(int $v): void       { $this->minRefreshSecs = max(1, $v); }
    #[InjectConfig('max_refresh_secs')]      public function setMaxRefreshSecs(int $v): void       { $this->maxRefreshSecs = max(1, $v); }
    #[InjectConfig('online_window_secs')]    public function setOnlineWindowSecs(int $v): void     { $this->onlineWindowSecs = max(5, $v); }
    // Nick length bounds feed a `{min,max}` regex quantifier in ChatLoginController;
    // clamping each independently could leave min > max (an invalid quantifier that
    // would fail to compile). Enforce max >= min in BOTH setters so the invariant
    // holds regardless of config-injection order.
    #[InjectConfig('nick_min_len')]          public function setNickMinLen(int $v): void           { $this->nickMinLen = max(1, $v); if ($this->nickMaxLen < $this->nickMinLen) { $this->nickMaxLen = $this->nickMinLen; } }
    #[InjectConfig('nick_max_len')]          public function setNickMaxLen(int $v): void           { $this->nickMaxLen = max(1, $v); if ($this->nickMaxLen < $this->nickMinLen) { $this->nickMaxLen = $this->nickMinLen; } }
    #[InjectConfig('names_link_to_profile')] public function setNamesLinkToProfile(bool $v): void  { $this->namesLinkToProfile = $v; }
    #[InjectConfig('allow_user_color')]      public function setAllowUserColor(bool $v): void      { $this->allowUserColor = $v; }
    #[InjectConfig('default_color')]         public function setDefaultColor(string $v): void      { $this->defaultColor = trim($v); }
    #[InjectConfig('allow_pm')]              public function setAllowPm(bool $v): void             { $this->allowPm = $v; }
    #[InjectConfig('pm_retention_minutes')]  public function setPmRetentionMinutes(int $v): void   { $this->pmRetentionMinutes = max(1, $v); }
    /** @param list<string> $v */
    #[InjectConfig('censor_words')]          public function setCensorWords(array $v): void        { $this->censorWords = array_values(array_filter($v, 'is_string')); }
    #[InjectConfig('censor_mode')]           public function setCensorMode(string $v): void        { $this->censorMode = $v === 'block' ? 'block' : 'replace'; }
    #[InjectConfig('censor_replacement')]    public function setCensorReplacement(string $v): void { $this->censorReplacement = $v; }
    #[InjectConfig('room_topic')]            public function setRoomTopic(string $v): void         { $this->roomTopic = $v; }
    #[InjectConfig('room_rules')]            public function setRoomRules(string $v): void         { $this->roomRules = $v; }
    #[InjectConfig('max_online')]            public function setMaxOnline(int $v): void            { $this->maxOnline = max(0, $v); }
    #[InjectConfig('show_timestamps_default')] public function setShowTimestampsDefault(bool $v): void { $this->showTimestampsDefault = $v; }
    #[InjectConfig('timestamp_format')]      public function setTimestampFormat(string $v): void   { $this->timestampFormat = $v !== '' ? $v : 'H:i'; }
    #[InjectConfig('chat_name')]             public function setChatName(string $v): void          { $this->chatName = $v; }
    #[InjectConfig('greeting_message')]      public function setGreetingMessage(string $v): void   { $this->greetingMessage = $v; }
    #[InjectConfig('default_bg_color')]      public function setDefaultBgColor(string $v): void    { $this->defaultBgColor = trim($v); }
    #[InjectConfig('default_font_family')]   public function setDefaultFontFamily(string $v): void { $this->defaultFontFamily = trim($v); }
    #[InjectConfig('default_timezone')]      public function setDefaultTimezone(string $v): void   { $this->defaultTimezone = trim($v); }
    #[InjectConfig('disable_guest_pm')]      public function setDisableGuestPm(bool $v): void      { $this->disableGuestPm = $v; }
    #[InjectConfig('kick_penalty_minutes')]  public function setKickPenaltyMinutes(int $v): void   { $this->kickPenaltyMinutes = max(0, $v); }
    #[InjectConfig('nick_regex')]            public function setNickRegex(string $v): void         { $this->nickRegex = trim($v); }
    #[InjectConfig('hide_help_button')]      public function setHideHelpButton(bool $v): void      { $this->hideHelpButton = $v; }
    #[InjectConfig('hide_profile_button')]   public function setHideProfileButton(bool $v): void   { $this->hideProfileButton = $v; }
    #[InjectConfig('hide_notes_button')]     public function setHideNotesButton(bool $v): void     { $this->hideNotesButton = $v; }
    #[InjectConfig('hide_rules_button')]     public function setHideRulesButton(bool $v): void     { $this->hideRulesButton = $v; }
    #[InjectConfig('hide_admin_button')]     public function setHideAdminButton(bool $v): void     { $this->hideAdminButton = $v; }
    #[InjectConfig('hide_clone_button')]     public function setHideCloneButton(bool $v): void     { $this->hideCloneButton = $v; }
    #[InjectConfig('hide_reload_button')]    public function setHideReloadButton(bool $v): void    { $this->hideReloadButton = $v; }
    #[InjectConfig('hide_rearrange_button')] public function setHideRearrangeButton(bool $v): void  { $this->hideRearrangeButton = $v; }

    #[InjectConfig('uploads_enabled')]       public function setUploadsEnabled(bool $v): void      { $this->uploadsEnabled = $v; }
    #[InjectConfig('uploads_guests')]        public function setUploadsGuests(bool $v): void       { $this->uploadsGuests = $v; }
    #[InjectConfig('upload_max_kb')]         public function setUploadMaxKb(int $v): void          { $this->uploadMaxKb = max(1, $v); }
    #[InjectConfig('upload_max_dimension')]  public function setUploadMaxDimension(int $v): void   { $this->uploadMaxDimension = max(0, $v); }
    #[InjectConfig('upload_types')]          public function setUploadTypes(string $v): void       { $this->uploadTypesRaw = trim($v); }
    #[InjectConfig('upload_dir')]            public function setUploadDir(string $v): void         { $this->uploadDir = rtrim(trim($v), '/\\'); }

    // =========================================================================
    // Getters
    // =========================================================================

    public function guestPosting(): bool        { return $this->guestPosting; }
    public function guestCaptcha(): bool        { return $this->guestCaptcha; }
    public function stripExif(): bool           { return $this->stripExif; }
    public function requireLoginToRead(): bool  { return $this->requireLoginToRead; }
    public function entryPassword(): string     { return $this->entryPassword; }
    public function chatEnabled(): bool         { return $this->chatEnabled; }
    public function disabledMessage(): string   { return $this->disabledMessage; }
    public function guestAccessMode(): string   { return $this->guestAccessMode; }
    public function approvalFallbackWaiting(): bool { return $this->approvalFallbackWaiting; }
    public function membersOnly(): bool         { return $this->guestAccessMode === self::ACCESS_MEMBERS_ONLY; }
    public function announceJoinLeave(): bool   { return $this->announceJoinLeave; }
    public function announceModActions(): bool  { return $this->announceModActions; }
    public function imageEmbed(): bool          { return $this->imageEmbed; }
    public function waitingRoomSeconds(): int   { return $this->waitingRoomSeconds; }
    public function waitingRoomMandatory(): bool { return $this->waitingRoomMandatory; }
    public function maxLength(): int            { return $this->maxLength; }
    public function messagesShown(): int        { return $this->messagesShown; }
    public function retentionMinutes(): int     { return $this->retentionMinutes; }
    public function newestFirst(): bool         { return $this->newestFirst; }
    public function bbcodeEnabled(): bool       { return $this->bbcodeEnabled; }
    public function linkConversion(): bool      { return $this->linkConversion; }
    public function minFloodSecs(): int         { return $this->minFloodSecs; }
    public function floodMuteSecs(): int        { return $this->floodMuteSecs; }
    public function defaultRefreshSecs(): int   { return $this->defaultRefreshSecs; }
    public function minRefreshSecs(): int       { return $this->minRefreshSecs; }
    public function maxRefreshSecs(): int       { return $this->maxRefreshSecs; }
    public function onlineWindowSecs(): int     { return $this->onlineWindowSecs; }
    public function nickMinLen(): int           { return $this->nickMinLen; }
    public function nickMaxLen(): int           { return $this->nickMaxLen; }
    public function namesLinkToProfile(): bool  { return $this->namesLinkToProfile; }
    public function allowUserColor(): bool      { return $this->allowUserColor; }
    public function defaultColor(): string      { return $this->defaultColor; }
    public function allowPm(): bool             { return $this->allowPm; }
    public function pmRetentionMinutes(): int   { return $this->pmRetentionMinutes; }
    /** @return list<string> */
    public function censorWords(): array        { return $this->censorWords; }
    public function censorMode(): string        { return $this->censorMode; }
    public function censorReplacement(): string { return $this->censorReplacement; }
    public function roomTopic(): string         { return $this->roomTopic; }
    public function roomRules(): string         { return $this->roomRules; }
    public function maxOnline(): int            { return $this->maxOnline; }
    public function showTimestampsDefault(): bool { return $this->showTimestampsDefault; }
    public function timestampFormat(): string   { return $this->timestampFormat; }
    public function chatName(): string          { return $this->chatName; }
    public function greetingMessage(): string   { return $this->greetingMessage; }
    public function defaultBgColor(): string    { return $this->defaultBgColor; }
    public function defaultFontFamily(): string { return $this->defaultFontFamily; }
    public function defaultTimezone(): string   { return $this->defaultTimezone; }
    public function disableGuestPm(): bool      { return $this->disableGuestPm; }
    public function kickPenaltyMinutes(): int   { return $this->kickPenaltyMinutes; }
    public function nickRegex(): string         { return $this->nickRegex; }
    public function hideHelpButton(): bool      { return $this->hideHelpButton; }
    public function hideProfileButton(): bool   { return $this->hideProfileButton; }
    public function hideNotesButton(): bool     { return $this->hideNotesButton; }
    public function hideRulesButton(): bool     { return $this->hideRulesButton; }
    public function hideAdminButton(): bool     { return $this->hideAdminButton; }
    public function hideCloneButton(): bool     { return $this->hideCloneButton; }
    public function hideReloadButton(): bool    { return $this->hideReloadButton; }
    public function hideRearrangeButton(): bool { return $this->hideRearrangeButton; }

    public function uploadsEnabled(): bool      { return $this->uploadsEnabled; }
    public function uploadsGuests(): bool       { return $this->uploadsGuests; }
    public function uploadMaxKb(): int          { return $this->uploadMaxKb; }
    public function uploadMaxBytes(): int       { return $this->uploadMaxKb * 1024; }
    public function uploadMaxDimension(): int   { return $this->uploadMaxDimension; }
    public function uploadDir(): string         { return \AstrX\Support\resourceStorageDir($this->uploadDir, 'chat_uploads'); }
    public function uploadTypesRaw(): string    { return $this->uploadTypesRaw; }

    /** @return list<string> */
    public function uploadTypes(): array
    {
        $out = [];
        foreach (explode(',', strtolower($this->uploadTypesRaw)) as $t) {
            $t = trim($t);
            if ($t !== '') { $out[] = $t; }
        }
        return $out === [] ? ['jpg', 'jpeg', 'png', 'gif', 'webp'] : $out;
    }
}
