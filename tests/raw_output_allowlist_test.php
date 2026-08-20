<?php
declare(strict_types=1);

/**
 * Raw-output ({{&x}}) allowlist lint — NO AstrX bootstrap, no database.
 *
 * `{{x}}` escapes. `{{&x}}` does not: whatever the binding holds is written into
 * the page verbatim. Every one of them is a place where an injected string
 * becomes markup, so every one of them needs a reason. Two had drifted:
 *
 *   - user/profile.html rendered {{&profile_avatar_src}} raw while
 *     partials/comments.html used the escaped {{avatar_src}} for the identical
 *     <img src> construct — the same value, two different rules.
 *   - board.html rendered {{&lbl_formatting_hint}}, a TRANSLATION string, raw.
 *     Lang files are editable from the Language admin page, so that was an
 *     admin-tier stored-XSS sink reachable without touching any code.
 *
 * A reviewer found those two. This is what finds the third: adding a `{{&x}}`
 * to a template now fails the lint until someone writes down, on one line, what
 * produces the value and why it is trusted HTML.
 *
 * Wire into CI next to the other portable gates:
 *     php tests/raw_output_allowlist_test.php
 *
 * Run:  php tests/raw_output_allowlist_test.php
 */

$ROOT         = dirname(__DIR__);
$TEMPLATE_DIR = $ROOT . '/resources/template';

/**
 * binding name => why it may bypass escaping.
 *
 * A trailing '*' matches a family (e.g. 'csrf.*' covers csrf.change_password).
 * Keep every justification to ONE line, and name the code that produces the
 * value — "it is safe" is not a justification, "X builds this markup" is.
 */
const RAW_ALLOWLIST = [
    // ── Server-built markup: produced by PHP that escapes its own inputs ──────
    'sid_input'                  => 'DefaultTemplateContext builds this <input> and htmlspecialchars() both the name and the session id.',
    'css'                        => 'ThemeService stylesheet text inlined into <style>; a theme file is operator-installed, not user input.',
    'chat_css'                   => 'ChatStyles::shellCss() — a PHP constant, no runtime input at all.',
    'comments_html'              => 'The comments partial, already rendered by TemplateEngine with its own escaping.',
    'close_divs_html'            => 'CommentController: str_repeat("</div>", $depth) — a bounded integer, no text.',
    'comments_base_query_inputs' => 'DefaultTemplateContext builds these hidden <input>s and escapes every value.',
    'news_comment_inputs'        => 'DefaultTemplateContext builds these hidden <input>s and escapes every value.',
    'link'                       => 'Pagination anchor built in DefaultTemplateContext with htmlspecialchars($url) and an integer label.',
    'captcha_image'              => 'Base64 GIF/PNG bytes from CaptchaService, interpolated into a data: URI.',
    'image_b64'                  => 'Same base64 captcha payload on the captcha test page.',
    'graph_svg'                  => 'ContentService renders this SVG itself from slugs it escapes.',
    'page_html'                  => 'ContentService::renderBody() — the Markdown renderer escapes HTML in the source before emitting.',
    'board_rules_html'           => 'BoardController renders board rules through the same Markdown/escaping path as post bodies.',
    'threads_html'               => 'BoardView-built post markup; every field goes through BoardView::e().',
    'posts_html'                 => 'BoardView-built post markup; every field goes through BoardView::e().',
    'catalog_html'               => 'BoardView::catalog() markup; every field goes through BoardView::e().',
    'overboard_html'             => 'BoardView::catalog() markup for the overboard; same escaping.',
    'mp_body'                    => 'Post body HTML that PostService already sanitised for the moderation panel.',
    'body_html_safe'             => 'WebmailController output of the HTML mail sanitiser — the whole point of that pass.',

    // ── Token maps: {{&csrf.x}} / {{&prg.x}} read one key out of an array ─────
    'csrf.*'                     => 'CsrfHandler tokens: 64 hex chars from bin2hex(random_bytes()), rendered inside a value="" attribute.',
    'prg.*'                      => 'PrgHandler ids: hex only, matched against prg_token_regex before use.',
];

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $cond): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok   - $label\n"; }
    else       { $FAIL++; echo "  FAIL - $label\n"; }
}

function eq(string $label, mixed $expected, mixed $actual): void
{
    $ok = $expected === $actual;
    check($label . ($ok ? '' : ' (expected ' . var_export($expected, true)
                             . ', got ' . var_export($actual, true) . ')'), $ok);
}

/** @return list<string> */
function templateFiles(string $dir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) { continue; }
        $path = $file->getPathname();
        if (!str_ends_with($path, '.html')) { continue; }
        // Compiled output, not source.
        if (str_contains(str_replace('\\', '/', $path), '/template/cache/')) { continue; }
        $out[] = $path;
    }
    sort($out);
    return $out;
}

function allowed(string $name): bool
{
    if (isset(RAW_ALLOWLIST[$name])) { return true; }
    foreach (array_keys(RAW_ALLOWLIST) as $entry) {
        if (str_ends_with($entry, '*')
            && str_starts_with($name, substr($entry, 0, -1))
        ) {
            return true;
        }
    }
    return false;
}

echo "\nRaw-output allowlist\n";

$files = templateFiles($TEMPLATE_DIR);
check('found template sources to scan', count($files) > 20);

/** @var array<string,list<string>> $found  binding name => files */
$found = [];
foreach ($files as $file) {
    $body = (string) file_get_contents($file);
    if (preg_match_all('/\{\{&\s*([^}\s]+)\s*\}\}/', $body, $m) < 1) { continue; }
    foreach ($m[1] as $name) {
        // '*' is the engine's dereference operator, not part of the name.
        $found[ltrim($name, '*')][] = substr($file, strlen($TEMPLATE_DIR) + 1);
    }
}
ksort($found);

$undocumented = [];
foreach ($found as $name => $files) {
    if (!allowed($name)) {
        $undocumented[] = $name . ' (' . implode(', ', array_unique($files)) . ')';
    }
}
check(
    'every {{&raw}} binding has a one-line justification'
    . ($undocumented === [] ? '' : ' — undocumented: ' . implode('; ', $undocumented)),
    $undocumented === [],
);

// A justification for a binding nobody renders any more is not documentation,
// it is a stale claim the next reader will trust.
$unused = [];
foreach (array_keys(RAW_ALLOWLIST) as $entry) {
    $isFamily = str_ends_with($entry, '*');
    $prefix   = $isFamily ? substr($entry, 0, -1) : $entry;
    $hit      = false;
    foreach (array_keys($found) as $name) {
        if ($isFamily ? str_starts_with($name, $prefix) : $name === $entry) { $hit = true; break; }
    }
    if (!$hit) { $unused[] = $entry; }
}
check(
    'no allowlist entry has outlived its binding'
    . ($unused === [] ? '' : ' — stale: ' . implode(', ', $unused)),
    $unused === [],
);

check(
    'every justification is a single line',
    array_filter(RAW_ALLOWLIST, static fn(string $why): bool => str_contains($why, "\n")) === [],
);

// ── The two the reviewer traced, pinned so they cannot come back ─────────────
echo "\nThe two fixed sinks\n";

$profile = (string) file_get_contents($TEMPLATE_DIR . '/user/profile.html');
check(
    'user/profile.html renders the avatar src ESCAPED, like partials/comments.html does',
    str_contains($profile, '{{profile_avatar_src}}') && !str_contains($profile, '{{&profile_avatar_src}}'),
);

$board = (string) file_get_contents($TEMPLATE_DIR . '/board.html');
check(
    'board.html renders the formatting hint ESCAPED (it is an admin-editable translation string)',
    str_contains($board, '{{lbl_formatting_hint}}') && !str_contains($board, '{{&lbl_formatting_hint}}'),
);

// The hint is now escaped, so an &gt; in the lang file would surface to the
// reader as the literal text "&gt;greentext".
foreach (['en' => 'Imageboard.en.php', 'it' => 'Imageboard.it.php'] as $locale => $langFile) {
    /** @var array<string,mixed> $lang */
    $lang = require dirname(__DIR__) . '/resources/lang/' . $locale . '/' . $langFile;
    $hint = $lang['board.formatting_hint'] ?? '';
    check(
        "{$locale}: board.formatting_hint holds a literal '>' , not an &gt; entity",
        is_string($hint) && str_contains($hint, '>') && !str_contains($hint, '&gt;'),
    );
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
