<?php
declare(strict_types=1);

/**
 * A complete AstrX application built from the framework layers only
 * (foundation + component): no database, no accounts, no CMS page table, no
 * modules, no JavaScript. It serves an HTML page, a form handled with
 * POST/redirect/GET, a JSON endpoint and a 404.
 *
 *   php -S 127.0.0.1:8000 examples/minimal/index.php
 *
 * tests/framework_isolation_test.php copies ONLY the framework namespaces next
 * to this file and serves it, which is what proves the split is real at
 * runtime and not just in the import graph. ASTRX_AUTOLOAD points it at that
 * copy; by default it uses this checkout's autoloader.
 */

use AstrX\Http\HttpStatus;
use AstrX\Http\ParameterBag;
use AstrX\Http\Request;
use AstrX\Http\RequestMethod;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\TemplateEngine;
use AstrX\Support\Paths;

require getenv('ASTRX_AUTOLOAD') ?: dirname(__DIR__, 2) . '/src/autoload.php';

$diagnostics = new DiagnosticsCollector();

$requestResult = Request::fromGlobals()->drainTo($diagnostics);
if (!$requestResult->isOk()) {
    Response::text('Bad request', HttpStatus::BAD_REQUEST->value)->send();
    return;
}
$request = $requestResult->unwrap();

// Where this application keeps its files. The framework reads no global for
// them: every class that needs one is handed this.
$paths = new Paths(
    lang:          __DIR__ . '/lang',
    templates:     __DIR__ . '/templates',
    templateCache: sys_get_temp_dir() . '/astrx-minimal-' . md5(__DIR__),
);

$t = new Translator($diagnostics, null, $paths);
$t->setLocale('en');
$t->load('App');

$views = new TemplateEngine($diagnostics, null, $paths);

/**
 * Render a template into an HTML response (escaped output, security headers).
 *
 * @param array<string, mixed> $vars
 */
function page(TemplateEngine $views, Translator $t, string $template, array $vars, int $status = 200): Response
{
    $rendered = $views->renderTemplate($template, $vars + ['site' => $t->t('app.name')]);
    return $rendered->isOk()
        ? Response::html($rendered->unwrap(), $status)
        : Response::text('Template error', HttpStatus::INTERNAL_SERVER_ERROR->value);
}

/** A trimmed string parameter; anything that is not a scalar counts as absent. */
function param(ParameterBag $bag, string $key): string
{
    $value = $bag->getString($key, '')->valueOr('');
    return is_string($value) ? trim($value) : '';
}

$name = param($request->query(), 'name');

$response = match ($request->uri()->path()) {
    '/' => $request->method() === RequestMethod::Post
        // POST/redirect/GET: the form result is a URL, not a re-submittable POST.
        ? Response::redirect('/?name=' . rawurlencode(param($request->body(), 'name')), HttpStatus::SEE_OTHER->value)
        : page($views, $t, 'home', [
            'heading'  => $t->t('app.heading'),
            'greeting' => $t->t('app.greeting', ['name' => $name !== '' ? $name : $t->t('app.stranger')]),
            'label'    => $t->t('app.label'),
            'submit'   => $t->t('app.submit'),
            'features' => array_map(static fn(string $f): array => ['text' => $f], [
                $t->t('app.feature.templates'),
                $t->t('app.feature.i18n'),
                $t->t('app.feature.results'),
            ]),
        ]),
    '/hello.json' => Response::json(['greeting' => $t->t('app.greeting', ['name' => $name !== '' ? $name : 'world'])]),
    default => page($views, $t, 'not_found', ['heading' => $t->t('app.not_found')], HttpStatus::NOT_FOUND->value),
};

$response->send();
