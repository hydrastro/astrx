<?php
declare(strict_types=1);

/**
 * Standalone PRG-handler + bot-trap test — NO AstrX bootstrap, no database.
 *
 *   1. CommentPrgHandler now garbage-collects abandoned COMMENT_POST_ payloads
 *      and unlinks the upload temp files they hold. Before the shared base
 *      class, only COMMENT_TARGET_ was pruned, so every comment form a visitor
 *      opened and never submitted stayed in the session blob for the life of
 *      the session — unbounded growth against a MEDIUMBLOB column.
 *   2. The two handlers still use disjoint session namespaces and query keys,
 *      which is the whole reason there are two of them.
 *   3. BotTrapController's tarpit is bounded in concurrency: with every slot
 *      held, a hit returns immediately instead of pinning another php-fpm
 *      worker in sleep().
 *
 * Run:  php tests/prg_bottrap_test.php
 */

namespace AstrX\Config {
    if (!\class_exists(InjectConfig::class)) {
        #[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
        final class InjectConfig
        {
            public function __construct(public readonly string $key) {}
        }
    }
}

namespace {

    use AstrX\BotTrap\BotTrapConfig;
    use AstrX\Controller\BotTrapController;
    use AstrX\Session\CommentPrgHandler;
    use AstrX\Session\PrgHandler;

    $CLASS_DIR = dirname(__DIR__) . '/src/AstrX/';
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

    function sessionKeysWithPrefix(string $prefix): int
    {
        $n = 0;
        foreach (array_keys($_SESSION) as $key) {
            if (str_starts_with((string) $key, $prefix)) { $n++; }
        }
        return $n;
    }

    echo "CommentPrgHandler payload GC\n";

    $_SESSION = [];
    $comment  = new CommentPrgHandler();

    $live = $comment->storeFromPayload(['content' => 'hello']);
    check('a stored payload is retrievable', $comment->has($live));
    check('and pulls back intact',           ($comment->pull($live) ?? [])['content'] === 'hello');
    check('pull() consumes it',              !$comment->has($live));

    // Abandoned payloads: stored, never pulled, meta timestamp aged past the TTL.
    $upload = tempnam(sys_get_temp_dir(), 'astrx_upload_');
    assert(is_string($upload));

    $stale = $comment->storeFromPayload([
        'content'   => 'abandoned',
        '__files__' => [['temp_path' => $upload]],
    ]);
    $_SESSION['COMMENT_POST_META_' . $stale] = time() - 7200;  // older than TARGET_TTL

    // Pre-fix rows have no meta at all; they must age out too.
    $_SESSION['COMMENT_POST_' . str_repeat('a', 64)] = ['content' => 'legacy'];

    check('the abandoned payloads are present before a sweep',
        sessionKeysWithPrefix('COMMENT_POST_') >= 2);

    $comment->createId('/some/page');    // a sweep point

    check('an abandoned COMMENT_POST_ payload is swept', !$comment->has($stale));
    check('a legacy COMMENT_POST_ payload with no meta is swept too',
        !$comment->has(str_repeat('a', 64)));
    check('its upload temp file is unlinked', !is_file($upload));
    check('the meta row goes with it',
        !array_key_exists('COMMENT_POST_META_' . $stale, $_SESSION));

    // A FRESH payload must survive the sweep — GC must not eat live submissions.
    $fresh = $comment->storeFromPayload(['content' => 'in flight']);
    $comment->createId('/another/page');
    check('a fresh payload survives the sweep', $comment->has($fresh));

    // Only astrx_upload_-prefixed files are ever unlinked.
    $foreign = tempnam(sys_get_temp_dir(), 'not_ours_');
    assert(is_string($foreign));
    $bad = $comment->storeFromPayload(['__files__' => [['temp_path' => $foreign]]]);
    $_SESSION['COMMENT_POST_META_' . $bad] = time() - 7200;
    $comment->createId('/third/page');
    check('a temp file we did not create is left alone', is_file($foreign));
    @unlink($foreign);

    echo "\nnamespaces stay disjoint\n";

    $_SESSION = [];
    $plain   = new PrgHandler();
    $comment = new CommentPrgHandler();

    $pToken = $plain->storeFromPayload(['who' => 'page']);
    $cToken = $comment->storeFromPayload(['who' => 'comment']);

    check('the comment handler cannot see a page payload', !$comment->has($pToken));
    check('the page handler cannot see a comment payload', !$plain->has($cToken));
    check('the query keys differ',
        $plain->tokenQueryKey() !== $comment->tokenQueryKey());
    check('the comment query key is still _cp', CommentPrgHandler::QUERY_KEY === '_cp');
    check('the page query key is still _prg',   $plain->tokenQueryKey() === '_prg');

    $pId = $plain->createId('/page');
    $cId = $comment->createId('/page');
    check('targets are namespaced too', !$comment->hasTarget($pId) && !$plain->hasTarget($cId));
    check('getUrl() appends the handler\'s own key',
        str_contains($comment->getUrl($cId, 'tok'), '_cp=tok')
        && str_contains($plain->getUrl($pId, 'tok'), '_prg=tok'));
    check('getUrl() on an unknown id is empty, not an exception',
        $plain->getUrl('nope', 'tok') === '');

    echo "\nbot-trap tarpit concurrency bound\n";

    // flock() locks are per open-file-description, so handles opened in THIS
    // process still contend with the controller's — holding every slot here
    // reproduces "all workers already tarpitting".
    $held = [];
    for ($i = 0; $i < BotTrapConfig::MAX_CONCURRENT_TARPITS; $i++) {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astrx_bottrap_tarpit_' . $i . '.lock';
        $fh   = fopen($path, 'c');
        if ($fh !== false && flock($fh, LOCK_EX | LOCK_NB)) {
            $held[] = [$fh, $path];
        }
    }
    check('the test could claim every tarpit slot',
        count($held) === BotTrapConfig::MAX_CONCURRENT_TARPITS);

    $tarpit = new ReflectionMethod(BotTrapController::class, 'tarpitWithinConcurrencyBound');
    $controller = (new ReflectionClass(BotTrapController::class))->newInstanceWithoutConstructor();

    $start = microtime(true);
    $tarpit->invoke($controller, 2);
    $elapsedBlocked = microtime(true) - $start;
    check('with every slot held, the tarpit is SKIPPED (no worker pinned)',
        $elapsedBlocked < 0.5);

    foreach ($held as [$fh, $_path]) { flock($fh, LOCK_UN); fclose($fh); }

    $start = microtime(true);
    $tarpit->invoke($controller, 1);
    $elapsedFree = microtime(true) - $start;
    check('with a slot free, the tarpit DOES delay the bot', $elapsedFree >= 0.9);

    foreach ($held as [$_fh, $path]) { @unlink($path); }

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
