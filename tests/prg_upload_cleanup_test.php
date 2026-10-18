<?php
declare(strict_types=1);

/**
 * Site\PrgIntake — parked upload copies do not outlive their request.
 *
 * A POST's uploads are moved to <tmp>/astrx_upload_* and restored into the
 * settling GET's FileBag. Controllers often read and re-encode the bytes
 * instead of moving the file, and once the payload is pulled the PRG GC can
 * no longer find it — so the original upload used to stay in the temp dir
 * forever. Each scenario runs in a child PHP process, because the cleanup
 * happens at request shutdown:
 *
 *   1. payload consumed (the normal case)   → temp copy deleted
 *   2. payload still pending (not pulled)   → kept; the PRG GC owns it
 *   3. a controller moved the file away     → nothing to delete, no error
 *   4. deleteTemps() never touches a file without the astrx_upload_ prefix
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\Site\PrgIntake;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

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

/**
 * Run one settling-GET request in a child process: park a payload holding
 * $tmp, restore it, then do what the "controller" does.
 *
 * @param 'pull'|'keep'|'move' $controller
 */
function settle(string $root, string $tmp, string $controller, string $movedTo = ''): string
{
    $code = <<<'PHP'
        [$root, $tmp, $controller, $movedTo] = array_slice($argv, 1);
        require $root . '/src/autoload.php';
        $_SESSION = [];
        $prg   = new AstrX\Session\PrgHandler();
        $token = $prg->storeFromPayload(['__files__' => ['image' => [
            'client_filename' => 'a.png', 'client_media_type' => 'image/png',
            'temp_path' => $tmp, 'size' => (int) filesize($tmp),
        ]]]);
        $request = new AstrX\Http\Request(
            AstrX\Http\RequestMethod::Get,
            new AstrX\Http\Uri('http', 'localhost', null, '/', '', ''),
            new AstrX\Http\HeaderBag([]),
            new AstrX\Http\ParameterBag([$prg->tokenQueryKey() => $token]),
            new AstrX\Http\ParameterBag([]), new AstrX\Http\ParameterBag([]),
            new AstrX\Http\ParameterBag([]), new AstrX\Http\ParameterBag([]),
            new AstrX\Http\FileBag([]),
        );
        (new AstrX\Site\PrgIntake(new AstrX\Result\DiagnosticsCollector()))->restoreUploads($request, $prg);
        $file = $request->files()->get('image');
        echo $file instanceof AstrX\Http\UploadedFile ? "restored\n" : "missing\n";
        if ($controller === 'pull') {
            $prg->pull($token);          // read the payload, re-encode the bytes elsewhere
        } elseif ($controller === 'move') {
            $prg->pull($token);
            $file->moveTo($movedTo);     // a controller that keeps the file
        }
        PHP;
    return (string) shell_exec(implode(' ', array_map('escapeshellarg', [
        PHP_BINARY, '-r', $code, $root, $tmp, $controller, $movedTo,
    ])));
}

function parked(): string
{
    $path = sys_get_temp_dir() . '/' . PrgIntake::TEMP_PREFIX . bin2hex(random_bytes(8));
    file_put_contents($path, 'upload bytes');
    return $path;
}

echo "1. Consumed payload\n";
$tmp = parked();
$out = settle($root, $tmp, 'pull');
check('the upload is restored into the FileBag', str_contains($out, 'restored'), $out);
check('the parked copy is gone after the request', !file_exists($tmp));

echo "\n2. Pending payload\n";
$tmp = parked();
settle($root, $tmp, 'keep');
check('a payload nobody consumed keeps its file (the PRG GC owns it)', file_exists($tmp));
@unlink($tmp);

echo "\n3. A controller that moves the file\n";
$tmp  = parked();
$dest = sys_get_temp_dir() . '/kept-' . bin2hex(random_bytes(6));
$out  = settle($root, $tmp, 'move', $dest);
check('the moved file is where the controller put it', is_file($dest) && file_get_contents($dest) === 'upload bytes', $out);
check('and the request ends cleanly', !str_contains($out, 'Warning') && !str_contains($out, 'Fatal'), $out);
@unlink($dest);

echo "\n4. Prefix guard\n";
$foreign = sys_get_temp_dir() . '/not-ours-' . bin2hex(random_bytes(6));
file_put_contents($foreign, 'x');
PrgIntake::deleteTemps([$foreign]);
check('a file without the prefix is never deleted', file_exists($foreign));
@unlink($foreign);

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
