<?php
declare(strict_types=1);

/**
 * Standalone admin-surface test — NO AstrX bootstrap, no database.
 *
 * Covers the two defects that let an anonymous visitor touch admin surfaces,
 * and the structure put in to stop them recurring:
 *
 *   1. migrate_zz_content_module.sql and migrate_zz_admin_language.sql inserted
 *      their pages' page_closure SELF row and forgot the WORDING_ADMIN parent
 *      row, so ContentManager's ancestry-walk admin guard did not fire for
 *      /en/admin-content or /en/admin-language: the login redirect was skipped
 *      and dispatch reached the controller, which rendered the admin shell under
 *      its own 403. The guard now ALSO reads the controller class, which no
 *      migration can edit.
 *
 *   2. /js/templates.js built its bundle with readdir + a prefix skip list, so
 *      the four admin templates that sat at the template ROOT shipped to any
 *      anonymous caller. The bundle is now derived from the page rows the caller
 *      is allowed to see.
 *
 * Run:  php tests/admin_surface_test.php
 */

namespace AstrX\Controller {
    // A controller that is an admin surface WITHOUT an Admin* name: proves the
    // attribute is read on its own, not just inferred from the class name.
    #[\AstrX\Support\RequiresPermission(\AstrX\Auth\Permission::ADMIN_ACCESS)]
    final class AstrxTestWidgetController {}
}

namespace {

    use AstrX\Auth\Permission;
    use AstrX\ContentManager;
    use AstrX\Controller\JsController;
    use AstrX\Page\Page;
    use AstrX\Template\DefaultTemplateContext;

    $ROOT      = dirname(__DIR__);
    $CLASS_DIR = $ROOT . '/src/AstrX/';
    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });

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

    /**
     * @param list<array{id:int,url_id:string,i18n:bool,file_name:string}> $ancestors
     */
    function page(string $fileName, bool $controller = true, array $ancestors = []): Page
    {
        return new Page(
            id: 100,
            urlId: 'WORDING_' . strtoupper($fileName),
            i18n: true,
            fileName: $fileName,
            template: true,
            controller: $controller,
            hidden: false,
            ancestors: $ancestors,
        );
    }

    /** @param list<array{id:int,url_id:string,i18n:bool,file_name:string}> $ancestors */
    function selfRow(int $id, string $fileName): array
    {
        return ['id' => $id, 'url_id' => 'WORDING_X', 'i18n' => true, 'file_name' => $fileName];
    }

    function requiredPermission(Page $p): ?Permission
    {
        /** @var ?Permission $out */
        $out = (new ReflectionMethod(ContentManager::class, 'requiredPagePermission'))->invoke(null, $p);
        return $out;
    }

    // =========================================================================
    echo "\n1. Page entry guard\n";

    // The page tree route (what already worked): admin_media has the parent row.
    eq(
        'a page with the admin root as a closure ancestor requires ADMIN_ACCESS',
        Permission::ADMIN_ACCESS,
        requiredPermission(page('admin_media', ancestors: [
            selfRow(31, 'admin_media'),
            selfRow(18, 'admin'),
        ])),
    );

    eq(
        'the admin root itself requires ADMIN_ACCESS',
        Permission::ADMIN_ACCESS,
        requiredPermission(page('admin', ancestors: [selfRow(18, 'admin')])),
    );

    // THE DEFECT: only the self row, exactly what the two migrations wrote.
    eq(
        'admin-content is gated even with NO admin ancestor row (the migration bug)',
        Permission::ADMIN_ACCESS,
        requiredPermission(page('admin_content', ancestors: [selfRow(41, 'admin_content')])),
    );
    eq(
        'admin-language is gated even with NO admin ancestor row (the migration bug)',
        Permission::ADMIN_ACCESS,
        requiredPermission(page('admin_language', ancestors: [selfRow(42, 'admin_language')])),
    );

    // The declarative marker, on a class whose name gives nothing away.
    eq(
        '#[RequiresPermission] on the controller class is enforced by itself',
        Permission::ADMIN_ACCESS,
        requiredPermission(page('astrx_test_widget', ancestors: [])),
    );

    // Public pages stay public.
    eq('a public page requires nothing', null, requiredPermission(page('main')));
    eq('the error page requires nothing', null, requiredPermission(page('error')));
    eq(
        'a template-only page with no controller requires nothing',
        null,
        requiredPermission(page('exit', controller: false)),
    );

    // The moderator panels are deliberately NOT admin: they gate on
    // CHAT_MODERATE / BOARD_MODERATE in-handler, and a chat mod holds neither
    // ADMIN_ACCESS nor an 'admin' ancestor. Gating them here would lock them out.
    eq(
        'chat_admin (CHAT_MODERATE) is not swept up by the Admin* rule',
        null,
        requiredPermission(page('chat_admin')),
    );
    eq(
        'board_mod (BOARD_MODERATE) is not swept up by the Admin* rule',
        null,
        requiredPermission(page('board_mod')),
    );

    // =========================================================================
    echo "\n2. Admin templates and the closure migration\n";

    check(
        'admin_content.html moved under admin/ with the other 30 admin templates',
        is_file($ROOT . '/resources/template/admin/admin_content.html')
        && !is_file($ROOT . '/resources/template/admin_content.html'),
    );
    check(
        'admin_language.html moved under admin/',
        is_file($ROOT . '/resources/template/admin/admin_language.html')
        && !is_file($ROOT . '/resources/template/admin_language.html'),
    );
    check(
        'the stale root-level admin_themes.html duplicate is gone (admin/ has the live one)',
        !is_file($ROOT . '/resources/template/admin_themes.html')
        && is_file($ROOT . '/resources/template/admin/admin_themes.html'),
    );

    $migration = $ROOT . '/src/setup/migrate_zzzz_admin_closure_fix.sql';
    check('a NEW migration ships the missing closure rows', is_file($migration));
    if (is_file($migration)) {
        $sql = (string) file_get_contents($migration);
        check(
            'it inserts the WORDING_ADMIN parent row for both pages',
            str_contains($sql, "a.url_id = 'WORDING_ADMIN'")
            && str_contains($sql, 'WORDING_ADMIN_CONTENT')
            && str_contains($sql, 'WORDING_ADMIN_LANGUAGE'),
        );
        check(
            'it is replay-safe (INSERT IGNORE), because migrations are checksum-locked',
            !str_contains($sql, 'INSERT INTO'),
        );
        check(
            'it sorts after the two migrations it repairs, so the pages exist by then',
            basename($migration) > 'migrate_zz_content_module.sql'
            && basename($migration) > 'migrate_zz_admin_language.sql',
        );
    }

    // =========================================================================
    echo "\n3. /js/templates.js bundle\n";

    // The bundle's include-path derivation must agree with the one the server
    // renders with, or the bundle ships a template under a name nothing uses.
    $assemble = new ReflectionMethod(JsController::class, 'assembleIncludePaths');
    $build    = new ReflectionMethod(DefaultTemplateContext::class, 'buildIncludePath');
    $ctx      = (new ReflectionClass(DefaultTemplateContext::class))->newInstanceWithoutConstructor();

    $cases = [
        // [page id, own file_name, ancestor rows (id => file_name), expected]
        [3,  'login',   [9 => 'user', 3 => 'login'],  'user/login'],
        [31, 'admin_media', [18 => 'admin', 31 => 'admin_media'], 'admin/admin_media'],
        [1,  'main',    [1 => 'main'],                'main'],
    ];
    foreach ($cases as [$id, $own, $ancestors, $expected]) {
        $closureRows = [];
        $pageAncestors = [];
        foreach ($ancestors as $ancestorId => $ancestorName) {
            $closureRows[]   = ['page_id' => $id, 'ancestor_file_name' => $ancestorName];
            $pageAncestors[] = selfRow($ancestorId, $ancestorName);
        }
        $fromBundle = $assemble->invoke(null, $closureRows, [$id], [$id => $own]);
        $fromServer = $build->invoke($ctx, page($own, ancestors: $pageAncestors));
        eq("bundle derives '{$expected}'", [$expected], $fromBundle);
        eq("server renders '{$expected}' (the two agree)", $expected, $fromServer);
    }

    // The allowlist, applied to the REAL template tree.
    $sources = new ReflectionMethod(JsController::class, 'templateSources');
    $root    = $ROOT . '/resources/template/';
    /** @var list<array{name:string,path:string,mtime:int,size:int}> $bundled */
    $bundled = $sources->invoke(null, $root, [
        'main'              => true,
        'default'           => true,
        'js_fragment'       => true,
        'partials/comments' => true,
    ]);
    $names = array_map(static fn(array $s): string => $s['name'], $bundled);
    sort($names);
    eq('only the allowed names are bundled', ['default', 'js_fragment', 'main', 'partials/comments'], $names);

    // Every file that leaked before, checked against an allowlist that does not
    // name them. 'admin' is the admin ROOT page's own template.
    $leaked = ['admin', 'admin/admin_content', 'admin/admin_language', 'admin/admin_themes',
               'bot_trap', 'board_mod', 'chat_admin', 'canary', 'mirrors'];
    $stillLeaking = array_values(array_intersect($leaked, $names));
    check(
        'no privileged template rides along'
        . ($stillLeaking === [] ? '' : ' — leaked: ' . implode(', ', $stillLeaking)),
        $stillLeaking === [],
    );

    // …and the guard is the allowlist, not the file's location: a name at the
    // template ROOT is excluded exactly as one under admin/ is. That is the
    // distinction the old str_starts_with('admin/') skip could not make.
    $rootAdmin = $sources->invoke(null, $root, ['admin' => true]);
    eq(
        'a root-level template IS bundled when a visible page maps to it',
        ['admin'],
        array_map(static fn(array $s): string => $s['name'], $rootAdmin),
    );

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
