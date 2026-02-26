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

final class Injector
{
    private const HELPER_NAME = 0;
    private const HELPER_INSTANCE = 1;
    private const HELPER_METHOD_NAME = 2;

    public const ID_HELPER_METHOD_NOT_FOUND   = 'astrx.injector/helper_method_not_found';
    public const LVL_HELPER_METHOD_NOT_FOUND  = DiagnosticLevel::ERROR;

    public const ID_HELPER_INVALID_SIGNATURE  = 'astrx.injector/helper_invalid_signature';
    public const LVL_HELPER_INVALID_SIGNATURE = DiagnosticLevel::ERROR;

    public const ID_HELPER_REFLECTION         = 'astrx.injector/helper_reflection_error';
    public const LVL_HELPER_REFLECTION        = DiagnosticLevel::ERROR;

    public const ID_CLASS_NOT_FOUND           = 'astrx.injector/class_not_found';
    public const LVL_CLASS_NOT_FOUND          = DiagnosticLevel::ERROR;

    public const ID_CLASS_REFLECTION          = 'astrx.injector/class_reflection_error';
    public const LVL_CLASS_REFLECTION         = DiagnosticLevel::ERROR;

    public const ID_UNRESOLVABLE_PARAMETER    = 'astrx.injector/unresolvable_parameter';
    public const LVL_UNRESOLVABLE_PARAMETER   = DiagnosticLevel::ERROR;

    public const ID_METHOD_NOT_FOUND          = 'astrx.injector/method_not_found';
    public const LVL_METHOD_NOT_FOUND         = DiagnosticLevel::ERROR;

    /** @var array<string, object> */
    private array $classes = [];

    /** @var array<string, array<string, mixed>> */
    private array $classesArgs = [];

    /** @var array<int, array<int, mixed>> */
    private array $helpers = [];

    public function __construct()
    {
        $this->classes['Injector'] = $this;
    }

    /** @return Result<bool> */
    public function addHelper(object $helperInstance, string $helperMethod): Result
    {
        $helperClass = get_class($helperInstance);

        if (!method_exists($helperInstance, $helperMethod)) {
            $d = Diagnostics::of(new HelperMethodNotFoundDiagnostic(
                                     self::ID_HELPER_METHOD_NOT_FOUND,
                                     self::LVL_HELPER_METHOD_NOT_FOUND,
                                     $helperClass,
                                     $helperMethod,
                                 ));
            return Result::err(false, $d);
        }

        try {
            $rm = new ReflectionMethod($helperClass, $helperMethod);
            $parameters = $rm->getParameters();

            if (\count($parameters) < 2) {
                $d = Diagnostics::of(new HelperInvalidSignatureDiagnostic(
                                         self::ID_HELPER_INVALID_SIGNATURE,
                                         self::LVL_HELPER_INVALID_SIGNATURE,
                                         $helperClass,
                                         $helperMethod
                                     ));
                return Result::err(false, $d);
            }

            $p0 = $parameters[0]->getType();
            $p1 = $parameters[1]->getType();

            if (!($p0 instanceof ReflectionNamedType) || $p0->getName() !== 'object') {
                $d = Diagnostics::of(new HelperInvalidSignatureDiagnostic(
                                         self::ID_HELPER_INVALID_SIGNATURE,
                                         self::LVL_HELPER_INVALID_SIGNATURE,
                                         $helperClass,
                                         $helperMethod
                                     ));
                return Result::err(false, $d);
            }

            if (!($p1 instanceof ReflectionNamedType) || $p1->getName() !== 'string') {
                $d = Diagnostics::of(new HelperInvalidSignatureDiagnostic(
                                         self::ID_HELPER_INVALID_SIGNATURE,
                                         self::LVL_HELPER_INVALID_SIGNATURE,
                                         $helperClass,
                                         $helperMethod
                                     ));
                return Result::err(false, $d);
            }
        } catch (ReflectionException $e) {
            $d = Diagnostics::of(new HelperReflectionDiagnostic(
                                     self::ID_HELPER_REFLECTION,
                                     self::LVL_HELPER_REFLECTION,
                                     $helperClass,
                                     $helperMethod,
                                     $e->getMessage(),
                                 ));
            return Result::err(false, $d);
        }

        $this->helpers[] = [
            self::HELPER_NAME => $helperClass,
            self::HELPER_INSTANCE => $helperInstance,
            self::HELPER_METHOD_NAME => $helperMethod,
        ];

        return Result::ok(true);
    }

    public function setClassArgs(string $className, array $args): void
    {
        $this->classesArgs[$this->getIndexName($className)] = $args;
    }

    public function getIndexName(string $class): string
    {
        return $class;
    }

    public function setClass(object $class): void
    {
        $this->classes[$this->getIndexName(get_class($class))] = $class;
    }

    /** @return Result<object|null> */
    public function getClass(string $className, bool $create = true): Result
    {
        $name = $this->getIndexName($className);

        if (isset($this->classes[$name])) {
            return Result::ok($this->classes[$name]);
        }

        if ($create) {
            return $this->createClass($className, true);
        }

        $d = Diagnostics::of(new ClassNotFoundDiagnostic(
                                 self::ID_CLASS_NOT_FOUND,
                                 self::LVL_CLASS_NOT_FOUND,
                                 $className,
                             ));
        return Result::err(null, $d);
    }

    /** @return Result<object|null> */
    public function createClass(string $className, bool $share = true): Result
    {
        if (!class_exists($className)) {
            $d = Diagnostics::of(new ClassNotFoundDiagnostic(
                                     self::ID_CLASS_NOT_FOUND,
                                     self::LVL_CLASS_NOT_FOUND,
                                     $className,
                                 ));
            return Result::err(null, $d);
        }

        try {
            $rc = new ReflectionClass($className);
            $dependencies = [];

            if ($rc->hasMethod('__construct')) {
                $ctor = $rc->getMethod('__construct');

                foreach ($ctor->getParameters() as $parameter) {
                    $argName = $parameter->getName();
                    $arg = $this->getClassArg($className, $argName);

                    if ($arg !== null) {
                        $dependencies[] = $arg;
                        continue;
                    }

                    if ($parameter->isOptional()) {
                        continue;
                    }

                    $type = $parameter->getType();
                    if (!($type instanceof ReflectionNamedType)) {
                        $d = Diagnostics::of(new UnresolvableParameterDiagnostic(
                                                 self::ID_UNRESOLVABLE_PARAMETER,
                                                 self::LVL_UNRESOLVABLE_PARAMETER,
                                                 $className,
                                                 $argName
                                             ));
                        return Result::err(null, $d);
                    }

                    $depClass = $type->getName();
                    $depResult = $this->getClass($depClass, true);

                    if (!$depResult->isOk()) {
                        return Result::err(null, $depResult->diagnostics());
                    }

                    $dependencies[] = $depResult->unwrap();
                }
            }

            $obj = new $className(...$dependencies);

            if ($share) {
                $this->classes[$this->getIndexName($className)] = $obj;
            }

            foreach ($this->helpers as $helper) {
                try {
                    $helper[self::HELPER_INSTANCE]->{$helper[self::HELPER_METHOD_NAME]}($obj, $className);
                } catch (\Throwable $t) {
                    $d = Diagnostics::of(new HelperReflectionDiagnostic(
                                             self::ID_HELPER_REFLECTION,
                                             self::LVL_HELPER_REFLECTION,
                                             (string)$helper[self::HELPER_NAME],
                                             (string)$helper[self::HELPER_METHOD_NAME],
                                             $t->getMessage(),
                                         ));
                    return Result::err(null, $d);
                }
            }

            return Result::ok($obj);
        } catch (ReflectionException $e) {
            $d = Diagnostics::of(new ClassReflectionDiagnostic(
                                     self::ID_CLASS_REFLECTION,
                                     self::LVL_CLASS_REFLECTION,
                                     $e->getMessage(),
                                 ));
            return Result::err(null, $d);
        }
    }

    /** @return Result<mixed> */
    public function callClassMethod(string $className, string $method, array $arguments = [], bool $create = false): Result
    {
        $classResult = $this->getClass($className, $create);
        if (!$classResult->isOk()) {
            return Result::err(null, $classResult->diagnostics());
        }

        $obj = $classResult->unwrap();

        if (!method_exists($obj, $method)) {
            $d = Diagnostics::of(new MethodNotFoundDiagnostic(
                                     self::ID_METHOD_NOT_FOUND,
                                     self::LVL_METHOD_NOT_FOUND,
                                     $className,
                                     $method,
                                 ));
            return Result::err(null, $d);
        }

        try {
            return Result::ok($obj->$method(...$arguments));
        } catch (\Throwable $t) {
            $d = Diagnostics::of(new ClassReflectionDiagnostic(
                                     self::ID_CLASS_REFLECTION,
                                     self::LVL_CLASS_REFLECTION,
                                     $t->getMessage(),
                                 ));
            return Result::err(null, $d);
        }
    }

    public function getClassArg(string $className, string $argName): mixed
    {
        $name = $this->getIndexName($className);

        if (!isset($this->classesArgs[$name]) || !array_key_exists($argName, $this->classesArgs[$name])) {
            return null;
        }

        return $this->classesArgs[$name][$argName];
    }
}