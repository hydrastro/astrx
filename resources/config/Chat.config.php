<?php
declare(strict_types=1);

/**
 * Chat configuration — every key here is editable from the admin chat-config
 * page (AdminConfigChatController) via ConfigWriter, exactly like the captcha
 * and system config sections. Runtime code reads these through the ChatConfig
 * holder (#[InjectConfig]); the section is keyed by that class's short name so
 * the module loader binds it (Chat.config.php resolves via the parent namespace).
 */
return [
    'ChatConfig' => [
        // ── Access ────────────────────────────────────────────────────────
        'guest_posting'          => true,   // may guests post at all
        'guest_captcha'          => true,   // require a captcha at the guest entry page
        'require_login_to_read'  => false,  // must you be in the chat to read it
        'entry_password'         => '',     // shared password guests must enter (blank = off)
        'chat_enabled'           => true,   // master switch; false shows the disabled message (staff bypass)
        'disabled_message'       => '',     // shown to non-staff when chat_enabled is false
        // Guest entry policy (le-chat "guestaccess"). Replaces the old members_only flag.
        'guest_access_mode'      => 'waiting_room', // open | waiting_room | moderator_approval | members_only
        'approval_fallback_waiting' => true,        // approval mode: fall back to the timed room when no mod is online

        // ── Waiting room ──────────────────────────────────────────────────
        'waiting_room_seconds'   => 10,     // seconds a guest waits before entry (0 = off)
        'waiting_room_mandatory' => false,  // true = no "enter now" skip link; guests must wait the full time

        // ── Messages ──────────────────────────────────────────────────────
        'max_length'             => 2000,   // max characters per message
        'messages_shown'         => 50,     // messages rendered in the stream
        'retention_minutes'      => 1440,   // message lifetime before GC
        'newest_first'           => true,   // stream order (newest at top)
        'bbcode_enabled'         => true,   // allow the BBCode subset
        'link_conversion'        => true,   // auto-link bare URLs
        'announce_join_leave'    => true,   // post a system line when someone joins/leaves
        'announce_mod_actions'   => true,   // post a system line when someone is kicked/banned/purged
        'image_embed'            => false,  // render image URLs inline as <img> (off by default: TOR privacy)

        // ── Rate limiting / flood ─────────────────────────────────────────
        'min_flood_secs'         => 3,      // min seconds between a poster's messages
        'flood_mute_secs'        => 30,     // auto-mute duration on flood breach

        // ── Refresh / presence ────────────────────────────────────────────
        'default_refresh_secs'   => 5,      // default frame auto-refresh cadence
        'min_refresh_secs'       => 2,      // floor a user may set
        'max_refresh_secs'       => 60,     // ceiling a user may set
        'online_window_secs'     => 45,     // last-seen window that counts as "online"

        // ── Nicknames ─────────────────────────────────────────────────────
        'nick_min_len'           => 2,
        'nick_max_len'           => 32,
        'names_link_to_profile'  => true,   // member names underscored + linked
        'nick_regex'             => '',     // custom nickname regex ('' = length rule only)

        // ── Colours ───────────────────────────────────────────────────────
        'allow_user_color'       => true,   // may users pick a text colour
        'default_color'          => '',     // '' = theme default
        'default_bg_color'       => '',     // global frame background ('' = theme default)
        'default_font_family'    => '',     // global default font key ('' = theme default)

        // ── Private messages ──────────────────────────────────────────────
        'allow_pm'               => true,
        'pm_retention_minutes'   => 1440,
        'disable_guest_pm'       => false,  // guests may not send PMs (members still can)

        // ── Word censor ───────────────────────────────────────────────────
        'censor_words'           => [],          // list of words/phrases to censor
        'censor_mode'            => 'replace',    // 'replace' | 'block'
        'censor_replacement'     => '***',        // used when mode = replace

        // ── Room ──────────────────────────────────────────────────────────
        'room_topic'             => '',     // shown above the stream
        'room_rules'             => '',     // shown on the entry page
        'max_online'             => 0,      // capacity (0 = unlimited)
        'chat_name'              => '',     // heading override ('' = default title)
        'greeting_message'       => '',     // shown above the stream ('' = none)

        // ── Timestamps ────────────────────────────────────────────────────
        'show_timestamps_default' => true,
        'timestamp_format'        => 'H:i',
        'default_timezone'        => '',    // global fallback IANA zone ('' = server local)

        // ── Moderation ────────────────────────────────────────────────────
        'kick_penalty_minutes'    => 10,    // temporary re-entry ban applied on kick (0 = off)

        // ── Toolbar ───────────────────────────────────────────────────────
        'hide_help_button'        => false, // hide the Help item in the chat toolbar
        'hide_profile_button'     => false, // hide the Profile item in the chat toolbar
        'hide_notes_button'       => false, // hide the Notes item
        'hide_rules_button'       => false, // hide the Rules item
        'hide_admin_button'       => false, // hide the Admin item (staff only anyway)
        'hide_clone_button'       => false, // hide the "open in new window" item
        'hide_reload_button'      => false, // hide the shell reload controls
        'hide_rearrange_button'   => false, // hide the shell rearrange-layout control

        // ── Phase 5: image attachments ──
        'uploads_enabled'         => false, // allow image attachments in posts
        'uploads_guests'          => false, // guests may attach (members may whenever enabled)
        'upload_max_kb'           => 2048,  // hard per-file size cap (KB)
        'upload_max_dimension'    => 1600,  // downscale over this many px on the longest side (0 = off)
        'upload_types'            => 'jpg,jpeg,png,gif,webp', // accepted extensions (comma list)
        'upload_dir'              => '/app/resources/chat_uploads', // absolute storage directory
    ],
];
