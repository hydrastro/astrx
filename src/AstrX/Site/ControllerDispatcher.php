<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Http\HttpStatus;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Kernel\Controller;
use AstrX\Page\Diagnostic\ControllerMissingDiagnostic;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Site\Controller\CommentController;
use function AstrX\Support\langDir;

/**
 * Runs the page's controller (from the ControllerMap), then the comment
 * controller when the page has comments.
 */
final class ControllerDispatcher
{
    public function __construct(
        private readonly Injector             $injector,
        private readonly Translator           $translator,
        private readonly DiagnosticsCollector $collector,
        private readonly ControllerMap        $controllers,
    ) {}

    /**
     * @param bool $commentsLocked a panic lockdown seals this visitor: do not
     *        accept comments even on a page the lockdown leaves open
     * @return HttpStatus|null the error status to render, or null to continue
     */
    public function dispatch(Page $page, bool $commentsLocked): ?HttpStatus
    {
        if ($page->controller) {
            // The same ControllerMap PageGate checked the entry permission
            // against — if the two ever resolved a different class, the gate
            // would be guarding a class that dispatch does not run.
            $fqcn = $this->controllers->classFor($page->fileName);

            if ($fqcn !== null && class_exists($fqcn)) {
                $controllerResult = $this->injector->createClass($fqcn)
                    ->drainTo($this->collector);

                if (!$controllerResult->isOk()) {
                    return HttpStatus::INTERNAL_SERVER_ERROR;
                }

                $controller = $controllerResult->unwrap();
                if ($controller instanceof Controller) {
                    $r = $controller->handle()->drainTo($this->collector);
                    if (!$r->isOk()) {
                        http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
                    }
                }
            } else {
                // The page declares controller=1 but no such controller class
                // exists — a seed/code mismatch (e.g. a config section seeded in
                // tables.sql whose controller was never implemented). Serve a clean
                // themed 404 rather than a raw 500, and return so we don't fall
                // through to a template render that also has no file. The diagnostic
                // keeps the misconfiguration visible in the logs.
                $this->collector->emit(new ControllerMissingDiagnostic(
                    'astrx.content/controller_missing', DiagnosticLevel::WARNING
                ));
                return HttpStatus::NOT_FOUND;
            }
        }

        // Dispatch the comment controller if comments are enabled on this page.
        // This runs AFTER the main controller so it can see any vars already set.
        // $commentsLocked carries the panic lockdown (see the class doc).
        if ($page->comments && !$commentsLocked) {
            // Load Comment lang domain — ModuleLoader would look for
            // CommentController.en.php (class short name), not Comment.en.php.
            $this->translator->loadDomain(langDir(), 'Comment');
            $commentFqcn   = CommentController::class;
            if (class_exists($commentFqcn)) {
                $commentResult = $this->injector->createClass($commentFqcn)
                    ->drainTo($this->collector);
                if ($commentResult->isOk()) {
                    $commentController = $commentResult->unwrap();
                    if ($commentController instanceof Controller) {
                        $commentController->handle()->drainTo($this->collector);
                    }
                }
            }
        }

        return null;
    }
}
