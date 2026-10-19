<?php
declare(strict_types=1);

/**
 * Site catalog pruning — `php tools/lang_prune.php [--write]`
 *
 * The site's resources/lang/ overrides the packages' and modules' catalogs key
 * by key. An install that predates a catalog moving into a package (the CMS's
 * Admin, User, … catalogs; a module's keys) still holds a full copy of it, and
 * every key in that copy that equals the package's value shadows the package
 * for nothing: a later release that fixes or rewords the string never reaches
 * the site.
 *
 * Without --write this lists, per domain and locale, how many of the site's
 * keys only repeat the shipped value. With --write it saves those domains the
 * way the admin Language editor does, which keeps only the keys the site
 * actually changed (and removes a file left with none). Keys the site changed
 * are never touched. Domains holding callables (Diagnostics/) are skipped.
 *
 * Exit 0; 1 when --write could not save a domain.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);
require_once $root . '/src/paths.php';
require_once $root . '/src/autoload.php';

use AstrX\ApplicationContainer;
use AstrX\I18n\LangCatalog;
use AstrX\Support\ResourceRoots;
use function AstrX\Support\langDir;

$write   = in_array('--write', $argv, true);
$roots   = ApplicationContainer::resourceRoots();
$catalog = new LangCatalog($roots);

/** @return array<string, string> the string entries of a catalog file */
function lp_strings(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    /** @var mixed $data */
    $data = include $file;
    $out = [];
    foreach (is_array($data) ? $data : [] as $k => $v) {
        if (is_string($k) && is_string($v)) {
            $out[$k] = $v;
        }
    }
    return $out;
}

$siteDir = rtrim(langDir(), '/\\') . DIRECTORY_SEPARATOR;
$total   = 0;
$failed  = false;
foreach ($catalog->domains() as $domain) {
    $redundant = [];
    foreach ($catalog->locales() as $locale) {
        $site = lp_strings($siteDir . $locale . DIRECTORY_SEPARATOR . $domain . '.' . $locale . '.php');
        if ($site === []) {
            continue;
        }
        $shipped = [];
        foreach ($roots->of(ResourceRoots::LANG) as $dir) {
            $shipped = array_merge($shipped, lp_strings($dir . $locale . DIRECTORY_SEPARATOR . $domain . '.' . $locale . '.php'));
        }
        $same = array_intersect_assoc($site, $shipped);
        if ($same !== []) {
            $redundant[$locale] = count($same);
        }
    }
    if ($redundant === []) {
        continue;
    }
    $n = array_sum($redundant);
    $total += $n;
    $parts = [];
    foreach ($redundant as $locale => $count) {
        $parts[] = "{$locale}: {$count}";
    }
    fwrite(STDOUT, sprintf("  %-24s %s\n", $domain, implode(', ', $parts)));
    if ($write) {
        $result = $catalog->save($domain, []);
        if (!$result->isOk()) {
            $failed = true;
            fwrite(STDERR, "  could not save {$domain}\n");
            continue;
        }
        // A site file left with no key of its own is just noise: remove it.
        foreach ($catalog->locales() as $locale) {
            $file = $siteDir . $locale . DIRECTORY_SEPARATOR . $domain . '.' . $locale . '.php';
            /** @var mixed $left */
            $left = is_file($file) ? include $file : null;
            if ($left === []) {
                @unlink($file);
            }
        }
    }
}

if ($total === 0) {
    fwrite(STDOUT, "No site catalog key repeats a shipped value.\n");
} elseif ($write) {
    fwrite(STDOUT, "Pruned {$total} key(s) that only repeated the shipped value.\n");
} else {
    fwrite(STDOUT, "{$total} site key(s) only repeat the shipped value; run with --write to drop them.\n");
}
exit($failed ? 1 : 0);
