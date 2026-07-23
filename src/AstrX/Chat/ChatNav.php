<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\I18n\Translator;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;

/**
 * Populates the chat toolbar context — a small chat-scoped nav bar
 * (Chat · Profile · Help) rendered by partials/chat_nav.html on every templated
 * chat page, and only there. Centralised so the shell, the profile page and the
 * help page show an identical bar without duplicating the URL/label wiring.
 *
 * The caller must have loaded the 'Chat' translation domain first (every chat
 * controller does at the top of handle()).
 */
final class ChatNav
{
    public function __construct(
        private readonly Translator   $t,
        private readonly UrlGenerator $urlGen,
        private readonly ChatConfig   $config,
        private readonly Gate         $gate,
    ) {}

    /** @param string $active which toolbar item is current: 'chat' | 'profile' | 'help' */
    public function apply(DefaultTemplateContext $ctx, string $active): void
    {
        $settingsUrl = $this->urlGen->toPage($this->t->t('WORDING_CHAT_SETTINGS'));
        $helpUrl     = $this->urlGen->toPage($this->t->t('WORDING_CHAT_HELP'));

        $ctx->set('chat_nav_show', true);

        // ── URLs ─────────────────────────────────────────────────────────────
        $ctx->set('nav_chat_url',    $this->urlGen->toPage($this->t->t('WORDING_CHAT')));
        $ctx->set('nav_profile_url', $settingsUrl);
        $ctx->set('nav_notes_url',   $settingsUrl . '#chat_notes');
        $ctx->set('nav_help_url',    $helpUrl);
        $ctx->set('nav_rules_url',   $helpUrl . '#chat-rules');
        $ctx->set('nav_admin_url',   $this->urlGen->toPage($this->t->t('WORDING_CHAT_ADMIN')));

        // ── Labels ───────────────────────────────────────────────────────────
        $ctx->set('nav_chat_label',    $this->t->t('chat.nav.chat'));
        $ctx->set('nav_profile_label', $this->t->t('chat.nav.profile'));
        $ctx->set('nav_notes_label',   $this->t->t('chat.nav.notes'));
        $ctx->set('nav_rules_label',   $this->t->t('chat.nav.rules'));
        $ctx->set('nav_help_label',    $this->t->t('chat.nav.help'));
        $ctx->set('nav_clone_label',   $this->t->t('chat.nav.clone'));
        $ctx->set('nav_admin_label',   $this->t->t('chat.nav.admin'));

        // ── Active state (the current page) ──────────────────────────────────
        $ctx->set('nav_on_chat',    $active === 'chat');
        $ctx->set('nav_on_profile', $active === 'profile');
        $ctx->set('nav_on_help',    $active === 'help');
        $ctx->set('nav_on_admin',   $active === 'admin');

        // ── Visibility: admin hide-toggles; Admin also requires moderator rights ─
        $ctx->set('nav_show_profile', !$this->config->hideProfileButton());
        $ctx->set('nav_show_notes',   !$this->config->hideNotesButton());
        $ctx->set('nav_show_rules',   !$this->config->hideRulesButton());
        $ctx->set('nav_show_help',    !$this->config->hideHelpButton());
        $ctx->set('nav_show_clone',   !$this->config->hideCloneButton());
        $ctx->set('nav_show_admin',   !$this->config->hideAdminButton() && $this->gate->can(Permission::CHAT_MODERATE));
    }
}
