<?php
declare(strict_types=1);

namespace AstrX\Theme;

use AstrX\Config\Config;
use AstrX\Config\InjectConfig;
use AstrX\User\UserSession;
use function AstrX\Support\templateDir;

/**
 * Theme discovery and resolution.
 *
 * Themes live in resources/template/themes/<name>/ and require two files
 * inside each theme directory:
 *   - style.css         the stylesheet
 *   - theme.config.php  metadata (name, description, author, version)
 *
 * Resolution order for the active theme on a given request:
 *   1. If allow_user_override is true AND the user is logged in AND has set
 *      a personal theme that still exists → use that.
 *   2. Else → use the globally configured theme (Theme.config.php → 'theme').
 *   3. If the resolved theme name doesn't exist → fall back to 'default'.
 *   4. If 'default' doesn't exist either → return null (caller must handle).
 *
 * The CSS content is read fresh on each request — no static cache here.
 * The opcache will keep theme.config.php fast; style.css is just file I/O.
 */
final class ThemeService
{
    private string $globalTheme       = 'default';
    private bool   $allowUserOverride = true;

    public function __construct(
        private readonly Config      $config,
        private readonly UserSession $session,
    ) {}

    #[InjectConfig('theme')]
    public function setGlobalTheme(string $v): void
    {
        $this->globalTheme = $v !== '' ? $v : 'default';
    }

    #[InjectConfig('allow_user_override')]
    public function setAllowUserOverride(bool $v): void
    {
        $this->allowUserOverride = $v;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the name of the theme that should be used for the current request.
     * Always returns a valid theme name — never throws, never returns empty.
     * If everything is broken, returns 'default' so the caller can still try
     * to load something (and decide what to do if that's missing too).
     */
    public function activeTheme(): string
    {
        // Step 1: per-user override
        if ($this->allowUserOverride && $this->session->isLoggedIn()) {
            $userTheme = $this->session->userTheme();
            if ($userTheme !== '' && $this->themeExists($userTheme)) {
                return $userTheme;
            }
        }
        // Step 2: global theme
        if ($this->themeExists($this->globalTheme)) {
            return $this->globalTheme;
        }
        // Step 3: fall back to default
        return 'default';
    }

    /**
     * Absolute path to the active theme's style.css.
     * Returns null if neither the active theme nor 'default' has a stylesheet.
     */
    public function activeStylesheetPath(): ?string
    {
        $theme = $this->activeTheme();
        $path  = $this->themeDir($theme) . 'style.css';
        if (is_file($path)) { return $path; }

        // Last-resort fallback — try the default theme regardless of what
        // activeTheme returned.
        $fallback = $this->themeDir('default') . 'style.css';
        return is_file($fallback) ? $fallback : null;
    }

    /**
     * Reads the active theme's CSS content. Returns empty string on failure
     * (better than null because the layout interpolates this directly into
     * a <style> block).
     */
    public function activeStylesheetContent(): string
    {
        $path = $this->activeStylesheetPath();
        if ($path === null) { return ''; }
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    /**
     * @return list<array{key:string, name:string, description:string, author:string, version:string}>
     */
    public function discoverThemes(): array
    {
        $themesRoot = $this->themesRoot();
        if (!is_dir($themesRoot)) { return []; }

        $themes = [];
        $entries = @scandir($themesRoot);
        if ($entries === false) { return []; }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $dir = $themesRoot . $entry . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) { continue; }

            $metaPath = $dir . 'theme.config.php';
            $cssPath  = $dir . 'style.css';
            if (!is_file($metaPath) || !is_file($cssPath)) { continue; }

            $meta = @include $metaPath;
            if (!is_array($meta)) { continue; }
            /** @var array<string,mixed> $meta */

            $themes[] = [
                'key'         => $entry,
                'name'        => is_string($meta['name']        ?? null) ? (string) $meta['name']        : $entry,
                'description' => is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
                'author'      => is_string($meta['author']      ?? null) ? (string) $meta['author']      : '',
                'version'     => is_string($meta['version']     ?? null) ? (string) $meta['version']     : '',
            ];
        }

        // Stable order: default first, then alphabetical by key
        usort($themes, function (array $a, array $b): int {
            if ($a['key'] === 'default') return -1;
            if ($b['key'] === 'default') return 1;
            return strcmp($a['key'], $b['key']);
        });

        return $themes;
    }

    public function themeExists(string $name): bool
    {
        if ($name === '') { return false; }
        // Reject anything that could escape the themes/ directory
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '.')) {
            return false;
        }
        return is_file($this->themeDir($name) . 'style.css');
    }

    public function globalTheme(): string         { return $this->globalTheme; }
    public function allowUserOverride(): bool     { return $this->allowUserOverride; }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function themesRoot(): string
    {
        return templateDir() . 'themes' . DIRECTORY_SEPARATOR;
    }

    private function themeDir(string $name): string
    {
        return $this->themesRoot() . $name . DIRECTORY_SEPARATOR;
    }
}
