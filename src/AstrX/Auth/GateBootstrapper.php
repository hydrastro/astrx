<?php
declare(strict_types=1);

namespace AstrX\Auth;

use AstrX\Auth\Policy\CommentPolicy;
use AstrX\Auth\Policy\NewsPolicy;
use AstrX\Auth\Policy\UserPolicy;

/**
 * Registers all Policies with the Gate at boot time.
 *
 * The Injector creates this class once when requested (lazily), and the
 * ModuleLoader::onClassCreated hook calls registerAll() via an Injector helper.
 *
 * Usage — add to Prelude after injector setup:
 *   $injector->addHelper($bootstrapper, 'onClassCreated');
 *   // GateBootstrapper is created by the injector when Gate is first resolved,
 *   // and registerAll() is called then.
 *
 * Alternatively, register it explicitly:
 *   $injector->createClass(GateBootstrapper::class)
 *            ->drainTo($collector)
 *            ->unwrap()
 *            ->registerAll($gate);
 */
final class GateBootstrapper
{
    /**
     * Register all known policies with the given Gate instance.
     * Called once at boot — idempotent.
     */
    public function registerAll(Gate $gate): void
    {
        $gate->registerPolicy(\AstrX\Comment\CommentRepository::class, new CommentPolicy());
        $gate->registerPolicy(\AstrX\News\NewsRepository::class,       new NewsPolicy());
        $gate->registerPolicy(\AstrX\User\UserRepository::class,       new UserPolicy());

        // stdClass resources (used in CommentService for anonymous comments)
        $gate->registerPolicy(\stdClass::class, new CommentPolicy());
    }
}
