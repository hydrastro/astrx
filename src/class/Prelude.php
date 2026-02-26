<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\TemplateEngine;

final class Prelude
{
    public function __construct()
    {
        $collector = new DiagnosticsCollector();

        $errorHandler = new ErrorHandler($collector);

        $config = new Config($collector);
        $config->addDeferredLangClass($config);

        $rawEnv = $config->getConfig('Prelude', 'environment', EnvironmentType::DEVELOPMENT->value);

        try {
            $env = EnvironmentType::from($rawEnv);
        } catch (\ValueError) {
            $env = EnvironmentType::DEVELOPMENT;
        }

        $errorHandler->setEnvironment($env);

        $injector = new Injector();
        $config->addDeferredLangClass($injector);

        // IMPORTANT: hook config/lang auto-loading + config injection
        $injector->addHelper($config, 'onClassCreated')->drainTo($collector);

        $injector->addHelper(
            new class($collector) {
                public function __construct(private \AstrX\Result\DiagnosticSinkInterface $sink) {}
                public function onClassCreated(object $obj, string $className):
                void
                {
                    if ($obj instanceof \AstrX\Result\DiagnosticSinkAwareInterface) {
                        $obj->setDiagnosticSink($this->sink);
                    }
                }
            },
            'onClassCreated'
        )->drainTo($collector);


        // share instances
        $injector->setClass($config);
        $injector->setClass($errorHandler);
        $injector->setClass($collector);
        $injector->setClass($this);


        $foo = $injector->createClass(\AstrX\Foo::class)
            ->drainTo($collector)
            ->unwrap();

        echo $foo->run();

        $templateEngine = $injector->createClass
        (\AstrX\Template\TemplateEngine::class)->drainTo($collector)->unwrap();
        assert($templateEngine instanceof TemplateEngine);
        $template = $templateEngine->loadTemplate("test");//->unwrap();
        // assert($template instanceof Template);
        echo "<pre>";
        print_r( $collector->diagnostics()->toArray());

        $render = $template->render(array("test"=>"this is a test", "ar"=>array("foo","bar","baz")));

        echo $render;


/*
        $contentManager = $injector->createClass("AstrX\\ContentManager")
            ->drainTo($collector)
            ->unwrap();

        assert($contentManager instanceof ContentManager);
        $contentManager->init();
*/
    }
}
