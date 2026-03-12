<?php
declare(strict_types=1);

namespace AstrX\Injector;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Injector\Diagnostic\ClassNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\ClassReflectionDiagnostic;
use AstrX\Injector\Diagnostic\HelperInvalidSignatureDiagnostic;
use AstrX\Injector\Diagnostic\HelperMethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\HelperReflectionDiagnostic;
use AstrX\Injector\Diagnostic\MethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\UnresolvableParameterDiagnostic;
use Throwable;
use AstrX\Injector\RegisteredHelper;

/** @internal Value object replacing the raw int-indexed helper array. */
final class Injector
{
    // Diagnostic IDs and levels kept as public constants for external reference.
    public const string ID_HELPER_METHOD_NOT_FOUND    = 'astrx.injector/helper_method_not_found';
    public const DiagnosticLevel LVL_HELPER_METHOD_NOT_FOUND = DiagnosticLevel::ERROR;

    public const string ID_HELPER_INVALID_SIGNATURE   = 'astrx.injector/helper_invalid_signature';
    public const DiagnosticLevel LVL_HELPER_INVALID_SIGNATURE = DiagnosticLevel::ERROR;

    public const string ID_HELPER_REFLECTION          = 'astrx.injector/helper_reflection_error';
    public const DiagnosticLevel LVL_HELPER_REFLECTION = DiagnosticLevel::ERROR;

    public const string ID_CLASS_NOT_FOUND            = 'astrx.injector/class_not_found';
    public const DiagnosticLevel LVL_CLASS_NOT_FOUND  = DiagnosticLevel::ERROR;

    public const string ID_CLASS_REFLECTION           = 'astrx.injector/class_reflection_error';
    public const DiagnosticLevel LVL_CLASS_REFLECTION = DiagnosticLevel::ERROR;

    public const string ID_UNRESOLVABLE_PARAMETER     = 'astrx.injector/unresolvable_parameter';
    public const DiagnosticLevel LVL_UNRESOLVABLE_PARAMETER = DiagnosticLevel::ERROR;

    public const string ID_METHOD_NOT_FOUND           = 'astrx.injector/method_not_found';
    public const DiagnosticLevel LVL_METHOD_NOT_FOUND = DiagnosticLevel::ERROR;

    /** @var array<string, object> Shared instances keyed by FQCN. */
    private array $classes = [];

    /** @var array<string, array<string, mixed>> Per-class constructor argument overrides. */
    private array $classesArgs = [];

    /** @var list<RegisteredHelper> */
    private array $helpers = [];

    public function __construct()
    {
        $this->classes[self::class] = $this;
    }

    // -------------------------------------------------------------------------
    // Helper registration
    // -------------------------------------------------------------------------

    /** @return Result<bool> */
    public function addHelper(object $helperInstance, string $helperMethod): Result
    {
        $helperClass = $helperInstance::class;

        if (!method_exists($helperInstance, $helperMethod)) {
            return Result::err(false, Diagnostics::of(
                new HelperMethodNotFoundDiagnostic(
                    self::ID_HELPER_METHOD_NOT_FOUND,
                    self::LVL_HELPER_METHOD_NOT_FOUND,
                    $helperClass,
                    $helperMethod,
                )
            ));
        }

        try {
            $rm         = new ReflectionMethod($helperClass, $helperMethod);
            $parameters = $rm->getParameters();

            if (count($parameters) < 2) {
                return $this->helperSignatureErr($helperClass, $helperMethod);
            }

            $p0 = $parameters[0]->getType();
            $p1 = $parameters[1]->getType();

            if (!($p0 instanceof ReflectionNamedType) || $p0->getName() !== 'object') {
                return $this->helperSignatureErr($helperClass, $helperMethod);
            }

            if (!($p1 instanceof ReflectionNamedType) || $p1->getName() !== 'string') {
                return $this->helperSignatureErr($helperClass, $helperMethod);
            }
        } catch (ReflectionException $e) {
            return Result::err(false, Diagnostics::of(
                new HelperReflectionDiagnostic(
                    self::ID_HELPER_REFLECTION,
                    self::LVL_HELPER_REFLECTION,
                    $helperClass,
                    $helperMethod,
                    $e->getMessage(),
                )
            ));
        }

        $this->helpers[] = new RegisteredHelper($helperClass, $helperInstance, $helperMethod);

        return Result::ok(true);
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function setClass(object $instance): void
    {
        $this->classes[$instance::class] = $instance;
    }

    /**
     * Bind an interface (or alias) to an already-registered concrete instance.
     *
     * Useful so that type-hinting an interface in a constructor resolves to
     * the shared concrete.
     *
     * Example:
     *   $injector->setClass($collector);
     *   $injector->bind(DiagnosticSinkInterface::class, DiagnosticsCollector::class);
     */
    public function bind(string $abstract, string $concrete): void
    {
        if (isset($this->classes[$concrete])) {
            $this->classes[$abstract] = $this->classes[$concrete];
        }
    }

    public function setClassArgs(string $className, array $args): void
    {
        $this->classesArgs[$className] = $args;
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /** @return Result<object|null> */
    public function getClass(string $className, bool $create = true): Result
    {
        if (isset($this->classes[$className])) {
            return Result::ok($this->classes[$className]);
        }

        if ($create) {
            return $this->createClass($className, true);
        }

        return Result::err(null, Diagnostics::of(
            new ClassNotFoundDiagnostic(self::ID_CLASS_NOT_FOUND, self::LVL_CLASS_NOT_FOUND, $className)
        ));
    }

    /** @return Result<object|null> */
    public function createClass(string $className, bool $share = true): Result
    {
        if (!class_exists($className)) {
            return Result::err(null, Diagnostics::of(
                new ClassNotFoundDiagnostic(self::ID_CLASS_NOT_FOUND, self::LVL_CLASS_NOT_FOUND, $className)
            ));
        }

        try {
            $rc           = new ReflectionClass($className);
            $dependencies = [];

            if ($rc->hasMethod('__construct')) {
                foreach ($rc->getMethod('__construct')->getParameters() as $parameter) {
                    $argName = $parameter->getName();
                    $arg     = $this->getClassArg($className, $argName);

                    if ($arg !== null) {
                        $dependencies[] = $arg;
                        continue;
                    }

                    if ($parameter->isOptional()) {
                        continue;
                    }

                    $type = $parameter->getType();
                    if (!($type instanceof ReflectionNamedType)) {
                        return Result::err(null, Diagnostics::of(
                            new UnresolvableParameterDiagnostic(
                                self::ID_UNRESOLVABLE_PARAMETER,
                                self::LVL_UNRESOLVABLE_PARAMETER,
                                $className,
                                $argName,
                            )
                        ));
                    }

                    $depResult = $this->getClass($type->getName(), true);
                    if (!$depResult->isOk()) {
                        return Result::err(null, $depResult->diagnostics());
                    }

                    $dependencies[] = $depResult->unwrap();
                }
            }

            $obj = new $className(...$dependencies);

            if ($share) {
                $this->classes[$className] = $obj;
            }

            foreach ($this->helpers as $helper) {
                try {
                    $helper->instance->{$helper->method}($obj, $className);
                } catch (Throwable $t) {
                    return Result::err(null, Diagnostics::of(
                        new HelperReflectionDiagnostic(
                            self::ID_HELPER_REFLECTION,
                            self::LVL_HELPER_REFLECTION,
                            $helper->className,
                            $helper->method,
                            $t->getMessage(),
                        )
                    ));
                }
            }

            return Result::ok($obj);
        } catch (ReflectionException $e) {
            return Result::err(null, Diagnostics::of(
                new ClassReflectionDiagnostic(
                    self::ID_CLASS_REFLECTION,
                    self::LVL_CLASS_REFLECTION,
                    $e->getMessage(),
                )
            ));
        }
    }

    /** @return Result<mixed> */
    public function callClassMethod(
        string $className,
        string $method,
        array $arguments = [],
        bool $create = false,
    ): Result {
        $classResult = $this->getClass($className, $create);
        if (!$classResult->isOk()) {
            return Result::err(null, $classResult->diagnostics());
        }

        $obj = $classResult->unwrap();

        if (!method_exists($obj, $method)) {
            return Result::err(null, Diagnostics::of(
                new MethodNotFoundDiagnostic(
                    self::ID_METHOD_NOT_FOUND,
                    self::LVL_METHOD_NOT_FOUND,
                    $className,
                    $method,
                )
            ));
        }

        try {
            return Result::ok($obj->$method(...$arguments));
        } catch (Throwable $t) {
            return Result::err(null, Diagnostics::of(
                new ClassReflectionDiagnostic(
                    self::ID_CLASS_REFLECTION,
                    self::LVL_CLASS_REFLECTION,
                    $t->getMessage(),
                )
            ));
        }
    }

    public function getClassArg(string $className, string $argName): mixed
    {
        return $this->classesArgs[$className][$argName] ?? null;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /** @return Result<bool> */
    private function helperSignatureErr(string $class, string $method): Result
    {
        return Result::err(false, Diagnostics::of(
            new HelperInvalidSignatureDiagnostic(
                self::ID_HELPER_INVALID_SIGNATURE,
                self::LVL_HELPER_INVALID_SIGNATURE,
                $class,
                $method,
            )
        ));
    }
}
