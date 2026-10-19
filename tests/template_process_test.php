<?php
declare(strict_types=1);

// Warm-cache regressions across fresh PHP processes, including the eval bundle.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/astrx-template-process-' . bin2hex(random_bytes(6));
mkdir($tmp . '/one', 0700, true);
mkdir($tmp . '/two', 0700, true);
$passed = 0;
function check(string $label, bool $ok): void {
    global $passed;
    if (!$ok) { throw new RuntimeException($label); }
    $passed++; echo "ok: $label\n";
}
function run(array $args): string {
    $process = proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start PHP'); }
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
    if ($code !== 0) { throw new RuntimeException($out . $err); }
    return $out;
}
file_put_contents($tmp . '/child.php', <<<'CHILD'
<?php
require $argv[1];
$e = new AstrX\Template\TemplateEngine(new AstrX\Result\DiagnosticsCollector());
$e->setTemplateDir($argv[2] . '/');
$e->setTemplateCacheDir($argv[3] . '/');
$e->setParseMode((int)$argv[4]);
$r = $e->renderTemplate('test', ['name'=>'world', 'key'=>'page', 'rows'=>[
    ['key'=>'a', 'flag'=>true], ['key'=>'b', 'flag'=>false], ['key'=>'c', 'flag'=>1],
]], $argv[5] === '1');
if (!$r->isOk()) { exit(2); }
echo $r->unwrap();
CHILD);
try {
    $bundle = $tmp . '/bundle.php';
    run([PHP_BINARY,$root . '/tools/compile.php','--out='.$bundle,
        '--front='.$tmp.'/front.php','--compile-front='.$tmp.'/compile-front.php']);
    check('bundle built', is_file($bundle));
    foreach ([$root . '/src/autoload.php', $bundle] as $loader) {
        $render = static fn(int $mode, bool $php = false, string $dir = 'one'): string => run([
            PHP_BINARY, $tmp.'/child.php', $loader, $tmp.'/'.$dir, $tmp.'/cache', (string)$mode, $php?'1':'0',
        ]);
        $file = $tmp . '/one/test.html';
        file_put_contents($file,'Hello {{name}}!');
        check('parsed cache', $render(1)==='Hello world!');
        check('plain mode ignores parsed cache', $render(0)==='Hello {{name}}!');
        check('parsed mode returns after plain', $render(1)==='Hello world!');
        $mtime=filemtime($file);
        file_put_contents($file,'Goodbye {{name}}!'); touch($file,$mtime);
        check('source change with preserved mtime', $render(1)==='Goodbye world!');
        file_put_contents($file,'<?php echo "PHP worked"; ?>');
        check('PHP off stays literal', $render(0)==='<?php echo "PHP worked"; ?>');
        check('PHP on uses another cache identity', $render(0,true)==='PHP worked');
        file_put_contents($file,'<?php echo __DIR__; ?>');
        file_put_contents($tmp.'/two/test.html','<?php echo __DIR__; ?>');
        check('first source directory', $render(0,true)===$tmp.'/one');
        check('second source directory', $render(0,true,'two')===$tmp.'/two');
        // A flag section inside a list item keeps the item as its context.
        file_put_contents($file,'{{#rows}}{{#flag}}{{key}}{{/flag}}{{^flag}}-{{/flag}}{{/rows}} {{key}}');
        check('a flag section sees the list item', $render(1)==='a-c page');
    }
    echo "$passed checks passed\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tmp);
}
