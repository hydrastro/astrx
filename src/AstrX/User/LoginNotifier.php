<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Module\ModuleRegistry;

/**
 * Tells every ENABLED module's {@see LoginListener} that a password was just
 * accepted. The login forms call this, so they name no module.
 */
final class LoginNotifier
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function passwordAccepted(string $password, bool $secondFactorPending): void
    {
        foreach ($this->modules->extensions(LoginListener::class) as $listener) {
            $listener->passwordAccepted($password, $secondFactorPending);
        }
    }
}
