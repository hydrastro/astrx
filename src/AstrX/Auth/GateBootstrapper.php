<?php
declare(strict_types=1);

namespace AstrX\Auth;

use AstrX\Auth\Policy\ChatPolicy;
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
     *
     * A policy is keyed by the class of the object callers actually PASS to
     * Gate::can(), not by the class that loads it. UserPolicy used to be keyed
     * on UserRepository::class, which is never passed as a resource: every
     * user.edit.any / user.delete.any / user.promote check fell to the
     * \stdClass entry below (because AdminUsersController built its target with
     * `(object) $row`) and was answered by CommentPolicy, which has no arm for
     * any of them. UserResource exists so that binding is expressible only one
     * way; see UserResource's class docblock.
     */
    public function registerAll(Gate $gate): void
    {
        $gate->registerPolicy(\AstrX\Comment\CommentRepository::class, new CommentPolicy());
        $gate->registerPolicy(\AstrX\News\NewsRepository::class,       new NewsPolicy());
        $gate->registerPolicy(\AstrX\User\UserResource::class,         new UserPolicy());
        $gate->registerPolicy(\AstrX\Chat\ChatMessageResource::class,  new ChatPolicy());

        // stdClass resources (comment rows cast with `(object)` by CommentService
        // and AdminCommentsController). Keep this LAST-resort entry limited to
        // comments: any other subsystem that needs a resource-level ruling must
        // introduce its own typed resource class rather than reuse \stdClass.
        $gate->registerPolicy(\stdClass::class, new CommentPolicy());
    }
}
