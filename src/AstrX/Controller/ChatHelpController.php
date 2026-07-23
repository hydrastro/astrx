<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Chat\ChatConfig;
use AstrX\Chat\ChatNav;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Chat Help page — file_name `chat_help`, template=1. A static informational
 * page reached from the chat toolbar: message formatting, the /me command,
 * private messages, the ignore list, the role markers, and the room rules.
 * Purely informational, so it needs no identity or PRG flow.
 */
final class ChatHelpController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly ChatConfig             $config,
        private readonly ChatNav                $nav,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        $this->ctx->set('help_heading',         $this->t->t('chat.help.heading'));
        $this->ctx->set('help_formatting_head', $this->t->t('chat.help.formatting_head'));
        $this->ctx->set('help_formatting',      $this->t->t('chat.formatting_hint'));
        $this->ctx->set('help_me_head',         $this->t->t('chat.help.me_head'));
        $this->ctx->set('help_me',              $this->t->t('chat.help.me'));
        $this->ctx->set('help_pm_head',         $this->t->t('chat.help.pm_head'));
        $this->ctx->set('help_pm',              $this->t->t('chat.help.pm'));
        $this->ctx->set('help_ignore_head',     $this->t->t('chat.help.ignore_head'));
        $this->ctx->set('help_ignore',          $this->t->t('chat.help.ignore'));
        $this->ctx->set('help_roles_head',      $this->t->t('chat.help.roles_head'));
        $this->ctx->set('help_roles',           $this->t->t('chat.help.roles'));

        $rules = $this->config->roomRules();
        $this->ctx->set('help_has_rules',  $rules !== '');
        $this->ctx->set('help_rules_head', $this->t->t('chat.help.rules_head'));
        $this->ctx->set('help_rules',      $rules);

        $this->nav->apply($this->ctx, 'help');
        return $this->ok();
    }
}
