<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/autoload.php';

use AstrX\Injector\Injector;
use AstrX\I18n\Translator;
use AstrX\Result\Result;

$result = Result::ok(20)
    ->map(static fn (int $n): int => $n * 2)
    ->andThen(static fn (int $n): Result => Result::ok($n + 2));
if (!$result->isOk()) { throw new RuntimeException('Calculation failed'); }
echo $result->unwrap(), "\n";

$injector = new Injector();
$resolved = $injector->getClass(Translator::class);
if (!$resolved->isOk() || !$resolved->unwrap() instanceof Translator) {
    throw new RuntimeException('Translator could not be constructed');
}
echo $resolved->unwrap()->t('greeting', fallback: 'Hello from AstrX'), "\n";
