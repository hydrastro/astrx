<?php
declare(strict_types=1);

/**
 * Routing\RouteParser — every URL shape the site answers to.
 *
 * Rewrite mode: /<locale>/[api/][<sid>/]<page>/<tail…>, locale optional.
 * Query mode:   ?lang=…&sid=…&page=…[&api=1].
 * A sid is only read from the URL in cookieless mode and only when it matches
 * the configured pattern. Parsed values are mirrored into CurrentUrl and the
 * request's query bag.
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\Http\FileBag;
use AstrX\Http\HeaderBag;
use AstrX\Http\ParameterBag;
use AstrX\Http\Request;
use AstrX\Http\RequestMethod;
use AstrX\Http\Uri;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\RouteMatch;
use AstrX\Routing\RouteParser;

require dirname(__DIR__) . '/src/autoload.php';

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   - {$label}\n";
    } else {
        $FAIL++;
        echo "  FAIL - {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

/** @param array<string, string> $query */
function request(array $query = []): Request
{
    return new Request(
        RequestMethod::Get,
        new Uri('http', 'localhost', null, '/', '', ''),
        new HeaderBag([]),
        new ParameterBag($query),
        new ParameterBag([]),
        new ParameterBag([]),
        new ParameterBag([]),
        new ParameterBag([]),
        new FileBag([]),
    );
}

function parser(bool $rewrite = true, bool $cookies = true, string $base = '/'): RouteParser
{
    return new RouteParser(
        urlRewrite:        $rewrite,
        basePath:          $base,
        availableLocales:  ['en', 'it'],
        defaultLocale:     'en',
        sessionUseCookies: $cookies,
        sessionIdRegex:    '/^[\da-fA-F]{256}$/',
        localeKey:         'lang',
        sessionKey:        'sid',
        pageKey:           'page',
        defaultPageToken:  'WORDING_MAIN',
    );
}

/**
 * @param array<string, string> $query
 * @return array{RouteMatch, Request, CurrentUrl}
 */
function parse(RouteParser $p, string $uri, array $query = []): array
{
    $request = request($query);
    $current = new CurrentUrl();
    return [$p->parse($request, $current, $uri), $request, $current];
}

$sid = str_repeat('ab', 128);

echo "1. Rewrite mode\n";
[$m, $req, $cur] = parse(parser(), '/en/main/2/desc');
check('/en/main/2/desc → en, main', $m->locale === 'en' && $m->pageToken === 'main');
check('…and the tail is [2, desc]', $cur->tail() === ['2', 'desc'], json_encode($cur->tail()) ?: '');
check('…mirrored into CurrentUrl and the query bag',
    $cur->get('page') === 'main' && $req->query()->get('lang') === 'en' && $req->query()->get('page') === 'main');
check('…not an API call', !$req->isApi());

[$m, , $cur] = parse(parser(), '/it/utente/login');
check('/it/utente/login → it, utente, tail [login]',
    $m->locale === 'it' && $m->pageToken === 'utente' && $cur->tail() === ['login']);

[$m, , $cur] = parse(parser(), '/main/2');
check('no locale: /main/2 → default locale, page main, tail [2] (R3-19)',
    $m->locale === 'en' && $m->pageToken === 'main' && $cur->tail() === ['2']);

[$m, , $cur] = parse(parser(), '/xx/main');
check('an unknown locale is not a locale: /xx/main → page xx', $m->locale === 'en' && $m->pageToken === 'xx');

[$m] = parse(parser(), '/');
check('/ → the default page', $m->pageToken === 'WORDING_MAIN' && $m->sidCandidate === null);
[$m] = parse(parser(), '/en/');
check('/en/ → the default page', $m->locale === 'en' && $m->pageToken === 'WORDING_MAIN');

[$m, $req] = parse(parser(), '/en/api/main');
check('/en/api/main → API call for page main', $req->isApi() && $m->pageToken === 'main');

[$m] = parse(parser(base: '/sub/'), '/sub/it/main');
check('base path /sub/ is stripped', $m->locale === 'it' && $m->pageToken === 'main');

echo "\n2. Session id in the URL\n";
[$m, $req] = parse(parser(cookies: false), "/en/{$sid}/main");
check('cookieless: /en/<sid>/main → sid candidate + page main',
    $m->sidCandidate === $sid && $m->pageToken === 'main' && $req->query()->get('sid') === $sid);
[$m] = parse(parser(cookies: false), '/en/abc/main');
check('cookieless: a malformed sid is a page token, not a sid', $m->sidCandidate === null && $m->pageToken === 'abc');
[$m] = parse(parser(cookies: true), "/en/{$sid}/main");
check('cookie mode: never reads a sid from the URL', $m->sidCandidate === null && $m->pageToken === $sid);
[$m] = parse(parser(cookies: false), "/en/api/{$sid}/main");
check('cookieless API: /en/api/<sid>/main', $m->sidCandidate === $sid && $m->pageToken === 'main');

echo "\n3. Query mode\n";
[$m, $req, $cur] = parse(parser(rewrite: false), '/ignored/path', ['lang' => 'it', 'page' => 'news']);
check('?lang=it&page=news → it, news (the path is ignored)',
    $m->locale === 'it' && $m->pageToken === 'news' && $cur->get('lang') === 'it');
[$m] = parse(parser(rewrite: false), '/', ['lang' => 'xx']);
check('?lang=xx → default locale, default page', $m->locale === 'en' && $m->pageToken === 'WORDING_MAIN');
[$m, $req] = parse(parser(rewrite: false), '/', ['page' => 'main', 'api' => '1']);
check('?api=1 → API call', $req->isApi());
[$m, $req] = parse(parser(rewrite: false), '/', ['page' => 'main', 'api' => '0']);
check('?api=0 → not an API call', !$req->isApi());
[$m] = parse(parser(rewrite: false, cookies: false), '/', ['sid' => $sid]);
check('cookieless query: ?sid=<sid> → sid candidate', $m->sidCandidate === $sid);
[$m] = parse(parser(rewrite: false, cookies: true), '/', ['sid' => $sid]);
check('cookie mode query: ?sid ignored', $m->sidCandidate === null);

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
