<?php

declare(strict_types=1);

// @phpstan-ignore-file

/**
 * AstrX compiled front controller.
 *
 * Generate/update the bundle with:
 *   php tools/compile.php
 *
 * In production you can point the web server to this file instead of
 * public/index.php. It keeps the normal resources/config directory external
 * while loading AstrX PHP classes from build/astrx.compiled.php.
 */

define(
    'INDEX_DIR',
    realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . (basename(__DIR__) === 'compile' ? '..' : '')) . DIRECTORY_SEPARATOR
);
const RESOURCES_DIR = INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR;
const LANG_DIR = RESOURCES_DIR . 'lang' . DIRECTORY_SEPARATOR;
const CONFIG_DIR = RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR;
const TEMPLATE_DIR = RESOURCES_DIR . 'template' . DIRECTORY_SEPARATOR;
const TEMPLATE_CACHE_DIR = TEMPLATE_DIR . 'cache' . DIRECTORY_SEPARATOR;
const SRC_DIR = INDEX_DIR . 'src' . DIRECTORY_SEPARATOR;
const CLASS_DIR = SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR;
const CONTROLLER_DIR = SRC_DIR . 'controller' . DIRECTORY_SEPARATOR;
const TEMPLATE_HANDLER_DIR = SRC_DIR . 'template_handler' . DIRECTORY_SEPARATOR;

set_include_path(__DIR__);

$compilePrefix = '/compile';
if ($compilePrefix !== null) {
    define('ASTRX_COMPILED_ROUTE_PREFIX', $compilePrefix);
    $_SERVER['ASTRX_COMPILED_MODE'] = '1';
    $_SERVER['ASTRX_COMPILED_ROUTE_PREFIX'] = $compilePrefix;

    $astrxPrefixPath = static function (string $path) use ($compilePrefix): string {
        if ($path === '' || $path[0] !== '/') {
            return $path;
        }
        if ($path === $compilePrefix || str_starts_with($path, $compilePrefix . '/')) {
            return $path;
        }
        if (preg_match('#^/(?:[a-z]{2})(?:/|$)#i', $path) === 1 || $path === '/') {
            return rtrim($compilePrefix, '/') . ($path === '/' ? '' : $path);
        }
        return $path;
    };

    $astrxStripPrefix = static function (string $uri) use ($compilePrefix): string {
        $parts = parse_url($uri);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $uri;
        $query = is_array($parts) && isset($parts['query']) ? '?' . (string) $parts['query'] : '';

        if ($path === $compilePrefix) {
            return '/' . $query;
        }
        if (str_starts_with($path, $compilePrefix . '/')) {
            $stripped = substr($path, strlen($compilePrefix));
            return ($stripped !== '' ? $stripped : '/') . $query;
        }
        return $uri;
    };

    $originalRequestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['ASTRX_ORIGINAL_REQUEST_URI'] = $originalRequestUri;
    $_SERVER['REQUEST_URI'] = $astrxStripPrefix($originalRequestUri);
    $_SERVER['SCRIPT_NAME'] = $compilePrefix . '/index.php';
    $_SERVER['PHP_SELF'] = $compilePrefix . '/index.php';

    if (!headers_sent()) {
        header('X-AstrX-Compiled: prefix=' . $compilePrefix);
    }

    header_register_callback(static function () use ($compilePrefix, $astrxPrefixPath): void {
        foreach (headers_list() as $headerLine) {
            if (stripos($headerLine, 'Location:') !== 0) {
                continue;
            }
            $location = trim(substr($headerLine, 9));
            $newLocation = $location;

            if (str_starts_with($location, '/')) {
                $newLocation = $astrxPrefixPath($location);
            } else {
                $parts = parse_url($location);
                $host = $_SERVER['HTTP_HOST'] ?? '';
                if (is_array($parts) && isset($parts['host'], $parts['path']) && strcasecmp((string) $parts['host'], $host) === 0) {
                    $path = $astrxPrefixPath((string) $parts['path']);
                    $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
                    $fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';
                    $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : 'http';
                    $newLocation = $scheme . '://' . $host . $path . $query . $fragment;
                }
            }

            if ($newLocation !== $location) {
                header_remove('Location');
                header('Location: ' . $newLocation, true, http_response_code() ?: 302);
            }
        }
    });

    ob_start(static function (string $html) use ($compilePrefix, $astrxPrefixPath): string {
        $contentType = '';
        foreach (headers_list() as $headerLine) {
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = strtolower($headerLine);
                break;
            }
        }
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return $html;
        }

        $rewriteAttr = static function (array $m) use ($astrxPrefixPath): string {
            $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = parse_url($url);
            $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $url;
            if ($path === '' || $path[0] !== '/') {
                return $m[0];
            }
            $query = is_array($parts) && isset($parts['query']) ? '?' . (string) $parts['query'] : '';
            $fragment = is_array($parts) && isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';
            $rewritten = $astrxPrefixPath($path) . $query . $fragment;
            if ($rewritten === $url) {
                return $m[0];
            }
            return $m[1] . '=' . $m[2] . htmlspecialchars($rewritten, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[2];
        };

        $html = preg_replace_callback('/\b(href|src|action|formaction|poster)=("|\')([^"\']*)\2/i', $rewriteAttr, $html) ?? $html;
        return $html;
    });
}

$bundle = INDEX_DIR . 'build' . DIRECTORY_SEPARATOR . 'astrx.compiled.php';
if (!is_file($bundle)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "AstrX compiled bundle is missing. Run: php tools/compile.php
";
    exit;
}

require $bundle;
\AstrX\Compiled\Bundle::boot();