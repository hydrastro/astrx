<?php
declare(strict_types=1);

namespace AstrX\Theme;

/**
 * Where a visitor's own theme choice comes from.
 *
 * ThemeService depends on this instead of the account session, so theming
 * does not import the accounts layer. The accounts layer implements it
 * (User\UserSession) and the composition root binds it.
 */
interface ThemePreference
{
    /** The theme this visitor chose, or '' for "no preference" (e.g. logged out). */
    public function preferredTheme(): string;
}
