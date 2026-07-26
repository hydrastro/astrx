<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\BotTrap\BotTrapNavContributor;
use AstrX\BotTrap\BotTrapPageGuard;
use AstrX\Chat\ChatNavContributor;
use AstrX\Config\Config;
use AstrX\Imageboard\ImageboardNavContributor;
use AstrX\Injector\Injector;

/**
 * Central switchboard for optional modules.
 *
 * The single source of truth for "is module X on?" is
 * resources/config/Modules.config.php (section 'Modules', one bool per module
 * key, default ON so existing installs are unaffected). Core code never names a
 * module: it asks this registry for the navigation vars and page guards that
 * ENABLED modules contribute, so turning a module off in that one config file
 * makes its nav, footer hooks and page guards disappear without touching core.
 *
 * The module→contributor wiring below (NAV / GUARDS) is the Phase-1 stand-in for
 * per-module manifests (module.php); it lives here in the Module layer, never in
 * ContentManager or DefaultTemplateContext. A DISABLED module is never
 * instantiated — its declared `defaults` are merged instead.
 */
final class ModuleRegistry
{
    /**
     * Nav contributors, keyed by module. `defaults` are merged verbatim when the
     * module is DISABLED — safe, diagnostic-free no-ops: a `false` partial slot
     * makes `{{> slot}}` skip cleanly (the engine only renders string slots).
     *
     * @var array<string, array{class: class-string, defaults: array<string,mixed>}>
     */
    private const array NAV = [
        'imageboard' => ['class' => ImageboardNavContributor::class, 'defaults' => ['board_nav' => false]],
        'chat'       => ['class' => ChatNavContributor::class,       'defaults' => ['chat_nav' => false]],
        'bottrap'    => ['class' => BotTrapNavContributor::class,    'defaults' => [
            'trap_enabled' => false, 'trap_url' => '', 'trap_link_text' => '',
        ]],
    ];

    /**
     * Page guards, keyed by module.
     *
     * @var array<string, class-string>
     */
    private const array GUARDS = [
        'bottrap' => BotTrapPageGuard::class,
    ];

    public function __construct(
        private readonly Config   $config,
        private readonly Injector $injector,
    ) {
        $this->config->loadModuleConfig('Modules');
    }

    /** Whether an optional module is enabled (default ON, incl. for unknown keys). */
    public function enabled(string $key): bool
    {
        return $this->config->getConfigBool('Modules', $key, true);
    }

    /**
     * Context vars contributed by the nav-providing modules: the real values
     * from each ENABLED module's contributor, or its declared disabled-defaults.
     * Merged into the base context by DefaultTemplateContext.
     *
     * @return array<string,mixed>
     */
    public function navVars(): array
    {
        $out = [];
        foreach (self::NAV as $key => $spec) {
            if ($this->enabled($key)) {
                $contributor = $this->build($spec['class']);
                if ($contributor instanceof NavContributor) {
                    foreach ($contributor->vars() as $k => $v) {
                        $out[$k] = $v;
                    }
                    continue;
                }
            }
            foreach ($spec['defaults'] as $k => $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Page guards contributed by the ENABLED modules.
     *
     * @return list<PageGuard>
     */
    public function pageGuards(): array
    {
        $out = [];
        foreach (self::GUARDS as $key => $class) {
            if (!$this->enabled($key)) {
                continue;
            }
            $guard = $this->build($class);
            if ($guard instanceof PageGuard) {
                $out[] = $guard;
            }
        }
        return $out;
    }

    /** Build a contributor/guard through the injector, or null if it can't be built. */
    private function build(string $class): ?object
    {
        $result = $this->injector->getClass($class);
        if (!$result->isOk()) {
            return null;
        }
        $instance = $result->unwrap();
        return is_object($instance) ? $instance : null;
    }
}
