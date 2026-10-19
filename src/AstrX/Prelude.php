<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Auth\Gate;
use AstrX\Auth\Identity;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleRegistry;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Theme\ThemePreference;
use AstrX\User\UserSession;
use AstrX\ErrorHandler\ErrorHandler;
use AstrX\ContentManager;
use AstrX\Support\Paths;

/**
 * The composition root: builds the application, then run() handles the
 * request. Constructing it starts no session and sends no output — that
 * happens in run().
 */
final class Prelude
{
    private readonly ContentManager $contentManager;

    public function __construct()
    {
        // Force UTC for every date()/timestamp render, independent of the host's
        // php.ini date.timezone / TZ. On a Tor hidden service the ambient server
        // timezone is a deanonymising signal (it stamps the operator's region onto
        // every post/comment/chat time); pinning UTC here removes that leak the
        // same way the feeds already use gmdate().
        date_default_timezone_set('UTC');

        $collector = new DiagnosticsCollector();

        // Where this installation keeps its files: the paths the front
        // controller declared (src/paths.php). The only place they are read;
        // everything else is handed this object.
        $paths = Paths::fromConstants();

        $errorHandler = new ErrorHandler($collector, $paths);

        // Configuration, translations, the module loader and the installed
        // modules — the same container a CLI tool builds.
        $injector = ApplicationContainer::build($collector, $errorHandler, $paths);
        $injector->setClass($this);

        // Bootstrap PBAC Gate — register all policies once at startup.
        // The security layer depends on the Identity contract, not on the
        // accounts layer: bind the account session as the request's Identity
        // (and as the visitor's ThemePreference) before anything asks for Gate,
        // DiagnosticVisibilityChecker or ThemeService.
        // Failure is non-fatal (Gate falls back to deny-all).
        $sessionResult = $injector->createClass(UserSession::class)->drainTo($collector);
        if ($sessionResult->isOk()) {
            $injector->bind(Identity::class, UserSession::class);
            $injector->bind(ThemePreference::class, UserSession::class);
        }
        $gateResult = $injector->createClass(Gate::class)->drainTo($collector);
        if ($gateResult->isOk()) {
            $gate = $gateResult->unwrap();
            assert($gate instanceof Gate);
            $modulePolicies = [];
            $registryResult = $injector->getClass(ModuleRegistry::class)->drainTo($collector);
            if ($registryResult->isOk()) {
                $registry = $registryResult->unwrap();
                if ($registry instanceof ModuleRegistry) {
                    $modulePolicies = $registry->enabledPolicies();
                    // Every permission any feature declares, for the grants editor.
                    $injector->setClass(ApplicationPermissions::registry($registry));
                }
            }
            ApplicationPolicies::register($gate, $modulePolicies);
            $injector->setClass($gate);
        }

        // Create ContentManager — guard unwrap() so a missing dependency produces
        // a clear RuntimeException (caught by ErrorHandler) rather than a generic
        // "called unwrap() on a failed Result" LogicException with no context.
        $cmResult = $injector->createClass(ContentManager::class)
            ->drainTo($collector);

        if (!$cmResult->isOk()) {
            throw new \RuntimeException('Failed to create ContentManager — check diagnostics.');
        }

        $cm = $cmResult->unwrap();
        assert($cm instanceof ContentManager);
        $this->contentManager = $cm;
    }

    /** Handle the current request: session, routing, dispatch, rendering. */
    public function run(): void
    {
        $this->contentManager->init();
    }
}
