<?php
declare(strict_types=1);

namespace AstrX\BotTrap;

use AstrX\I18n\Translator;
use AstrX\Module\NavContributor;
use AstrX\Routing\UrlGenerator;
use function AstrX\Support\langDir;

/**
 * Bot-trap's contribution to the site chrome: the hidden footer honeypot link.
 * It sets `trap_enabled` / `trap_url` / `trap_link_text`, which default.html's
 * footer renders as an off-screen `<a>` that humans never see and greedy bots
 * follow into the maze. Config + i18n are touched only when the trap feature is
 * enabled; while disabled it returns the same empty defaults the registry would
 * merge if the whole module were off. When the bottrap module is disabled in
 * Modules.config.php this class is never built at all.
 */
final class BotTrapNavContributor implements NavContributor
{
    public function __construct(
        private readonly BotTrapConfig $config,
        private readonly Translator    $t,
        private readonly UrlGenerator  $urlGen,
    ) {}

    /** @return array<string,mixed> */
    public function vars(): array
    {
        if (!$this->config->enabled()) {
            return ['trap_enabled' => false, 'trap_url' => '', 'trap_link_text' => ''];
        }

        $this->t->loadDomain(langDir(), 'BotTrap');
        return [
            'trap_enabled'   => true,
            'trap_url'       => $this->urlGen->toPage($this->t->t('WORDING_TRAP')),
            'trap_link_text' => $this->t->t('bottrap.link_text'),
        ];
    }
}
