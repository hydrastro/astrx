<?php
declare(strict_types=1);

namespace AstrX\Injector;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Injector\Diagnostic\CircularDependencyDiagnostic;
use AstrX\Injector\Diagnostic\ClassNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\ClassReflectionDiagnostic;
use AstrX\Injector\Diagnostic\HelperInvalidSignatureDiagnostic;
use AstrX\Injector\Diagnostic\HelperMethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\HelperReflectionDiagnostic;
use AstrX\Injector\Diagnostic\MethodCallExceptionDiagnostic;
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

    public const string ID_METHOD_CALL_EXCEPTION           = 'astrx.injector/method_call_exception';
    public const DiagnosticLevel LVL_METHOD_CALL_EXCEPTION = DiagnosticLevel::ERROR;

    public const string ID_UNRESOLVABLE_PARAMETER     = 'astrx.injector/unresolvable_parameter';
    public const DiagnosticLevel LVL_UNRESOLVABLE_PARAMETER = DiagnosticLevel::ERROR;

    public const string ID_METHOD_NOT_FOUND           = 'astrx.injector/method_not_found';
    public const DiagnosticLevel LVL_METHOD_NOT_FOUND = DiagnosticLevel::ERROR;

    public const string ID_CIRCULAR_DEPENDENCY        = 'astrx.injector/circular_dependency';
    public const DiagnosticLevel LVL_CIRCULAR_DEPENDENCY = DiagnosticLevel::ERROR;

    /** @var array<string, object> Shared instances keyed by FQCN. */
    private array $classes = [];

    /** @var array<string, array<string, mixed>> Per-class constructor argument overrides. */
    private array $classesArgs = [];

    /** @var array<string, true> Classes currently mid-construction (cycle guard). */
    private array $constructing = [];

    /** @var list<RegisteredHelper> */
    private array $helpers = [];

    /**
     * config.php ['Injector']['helpers_strict'].
     *
     * True (the default, and the historical behaviour): a helper that throws
     * fails the whole createClass() call, so a half-wired object is never
     * shared. False: the failure is still reported as a diagnostic, but the
     * object is returned. The trade is real — ModuleLoader::onClassCreated is
     * the only registered helper, and if it throws under strict mode EVERY
     * class in the request fails to build and the site serves a 500; under
     * non-strict mode the site stays up on hardcoded defaults with the reason
     * visible in the diagnostics.
     */
    private bool $helpersStrict = true;

    public function __construct()
    {
        $this->classes[self::class] = $this;
    }

    #[InjectConfig('helpers_strict')]
    public function setHelpersStrict(bool $strict): void
    {
        $this->helpersStrict = $strict;
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

    /** @param array<string,mixed> $args */
    public function setClassArgs(string $className, array $args): void
    {
        $this->classesArgs[$className] = $args;
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * @return Result<object|null>
     * @phpstan-return Result<object|null>
     */
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

        // Cycle guard (R4-15): getClass() resolves a not-yet-shared class by
        // recursing into createClass(), so mutual constructor deps (A needs B,
        // B needs A) would recurse until a stack-overflow fatal. Track classes
        // currently mid-construction and return a clean error Result on a
        // revisit instead. Cleared in finally so a later, legitimate build of
        // the same class still succeeds.
        if (isset($this->constructing[$className])) {
            return Result::err(null, Diagnostics::of(
                new CircularDependencyDiagnostic(
                    self::ID_CIRCULAR_DEPENDENCY,
                    self::LVL_CIRCULAR_DEPENDENCY,
                    $className,
                )
            ));
        }
        $this->constructing[$className] = true;

        try {
            $rc                = new ReflectionClass($className);
            $dependencies      = [];
            $helperDiagnostics = Diagnostics::empty();

            if ($rc->hasMethod('__construct')) {
                foreach ($rc->getMethod('__construct')->getParameters() as $parameter) {
                    $argName = $parameter->getName();
                    $arg     = $this->getClassArg($className, $argName);

                    // Key by parameter NAME so PHP unpacks these as named
                    // arguments (R4-15): a skipped optional parameter no longer
                    // shifts a later provided/resolved argument into the wrong
                    // positional slot.
                    if ($arg !== null) {
                        $dependencies[$argName] = $arg;
                        continue;
                    }

                    // Resolve the TYPE before consulting isOptional(). Checking
                    // optionality first meant `__construct(?Foo $x = null)` was
                    // skipped without ever attempting to resolve Foo, so the
                    // parameter always took its null default even when the
                    // container held a shared Foo. That is how TemplateEngine's
                    // `?DiagnosticSinkInterface $sink = null` ended up on a
                    // private collector nobody reads.
                    $type = $parameter->getType();
                    if (!($type instanceof ReflectionNamedType) || $type->isBuiltin()) {
                        // A scalar/array/union/intersection parameter is not
                        // something the container can supply. Optional → take the
                        // declared default; required → unresolvable.
                        if ($parameter->isOptional()) {
                            continue;
                        }
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
                        // Unresolvable class dependency: fall back to the default
                        // when there is one, fail otherwise.
                        if ($parameter->isOptional()) {
                            continue;
                        }
                        return Result::err(null, $depResult->diagnostics());
                    }

                    $dependencies[$argName] = $depResult->unwrap();
                }
            }

            $obj = new $className(...$dependencies);

            // Run helpers BEFORE registering in $this->classes so that a
            // helper failure does not leave a half-initialised object in the
            // registry (which would be returned on subsequent getClass() calls).
            foreach ($this->helpers as $helper) {
                try {
                    $helper->instance->{$helper->method}($obj, $className);
                } catch (Throwable $t) {
                    $diagnostics = Diagnostics::of(
                        new HelperReflectionDiagnostic(
                            self::ID_HELPER_REFLECTION,
                            self::LVL_HELPER_REFLECTION,
                            $helper->className,
                            $helper->method,
                            $t->getMessage(),
                        )
                    );
                    if ($this->helpersStrict) {
                        return Result::err(null, $diagnostics);
                    }
                    $helperDiagnostics = $helperDiagnostics->concat($diagnostics);
                }
            }

            if ($share) {
                $this->classes[$className] = $obj;
            }

            return Result::ok($obj, $helperDiagnostics);
        } catch (ReflectionException $e) {
            return Result::err(null, Diagnostics::of(
                new ClassReflectionDiagnostic(
                    self::ID_CLASS_REFLECTION,
                    self::LVL_CLASS_REFLECTION,
                    $e->getMessage(),
                )
            ));
        } catch (Throwable $t) {
            // ReflectionException alone is not enough. `new $className(...)`
            // raises a plain Error for an abstract class, an interface, or a
            // private constructor, and a constructor is free to throw anything
            // at all. Uncaught, those escape the Result envelope entirely: they
            // bypass every isOk() check at the call site and surface as a raw
            // 500 instead of the themed error page the diagnostics path
            // produces.
            return Result::err(null, Diagnostics::of(
                new ClassReflectionDiagnostic(
                    self::ID_CLASS_REFLECTION,
                    self::LVL_CLASS_REFLECTION,
                    $className . ': ' . $t->getMessage(),
                )
            ));
        } finally {
            unset($this->constructing[$className]);
        }
    }

    /**
     * @param array<string,mixed> $arguments
     * @return Result<mixed>
     */
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

        if ($obj === null || !method_exists($obj, $method)) {
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
                new MethodCallExceptionDiagnostic(
                    self::ID_METHOD_CALL_EXCEPTION,
                    self::LVL_METHOD_CALL_EXCEPTION,
                    $className,
                    $method,
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
