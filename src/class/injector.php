<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class Injector
 */
class Injector
{
    private const HELPER_NAME = 0;
    private const HELPER_INSTANCE = 1;
    private const HELPER_METHOD_NAME = 2;
    public const ERROR_HELPER_METHOD_NOT_FOUND = 0;
    public const ERROR_INVALID_HELPER_METHOD = 1;
    public const ERROR_HELPER_REFLECTION = 2;
    public const ERROR_CLASS_NOT_FOUND = 3;
    public const ERROR_CLASS_METHOD_NOT_FOUND = 4;
    public const ERROR_CLASS_NOT_FOUND_2 = 5;
    public const ERROR_CLASS_NOT_FOUND_3 = 6;
    public const ERROR_CLASS_OR_PARAMETER_NOT_FOUND = 7;
    public const ERROR_CLASS_REFLECTION = 8;
    public const ERROR_REFLECTION_PARAMETER = 9;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    /**
     * @var array<string, object> $classes Injector container classes.
     */
    private array $classes = array();
    /**
     * @var array<string, array<string,mixed>> $classesArgs Classes arguments.
     */
    private array $classesArgs;
    /**
     * @var array<int, array<int, mixed>> $helpers Helpers array.
     */
    private array $helpers = array();

    /**
     * Injector Constructor.
     */
    public function __construct()
    {
        $this->classes["Injector"] = $this;
    }

    /**
     * Add Helper.
     * Adds new helpers which will be called on classes creation.
     *
     * @param object $helper_instance
     * @param string $helper_method
     *
     * @return bool
     */
    public function addHelper(object $helper_instance, string $helper_method)
    : bool {
        $helper_class_name = get_class($helper_instance);
        if (!method_exists($helper_instance, $helper_method)) {
            $this->results[] = array(
                self::ERROR_HELPER_METHOD_NOT_FOUND,
                array(
                    "class_name" => $helper_class_name,
                    "method_name" => $helper_method
                )
            );

            return false;
        }

        try {
            $reflectedMethod = new ReflectionMethod(
                $helper_class_name, $helper_method
            );
            $parameters = $reflectedMethod->getParameters();
            $parameter0type = $parameters[0]->getType();
            $parameter1type = $parameters[1]->getType();
            if (!($parameter0type instanceof ReflectionNamedType) ||
                $parameter0type->getName() !== "object" ||
                !($parameter1type instanceof ReflectionNamedType) ||
                $parameter1type->getName() !== "string") {
                $this->results[] = array(
                    self::ERROR_INVALID_HELPER_METHOD,
                    array(
                        "class_name" => $helper_class_name,
                        "method_name" => $helper_method
                    )
                );

                return false;
            }
        } catch (ReflectionException) {
            $this->results[] = array(
                self::ERROR_HELPER_REFLECTION,
                array(
                    "class_name" => $helper_class_name,
                    "method_name" => $helper_method
                )
            );

            return false;
        }

        $this->helpers[] = array(
            self::HELPER_NAME => $helper_class_name,
            self::HELPER_INSTANCE => $helper_instance,
            self::HELPER_METHOD_NAME => $helper_method
        );

        return true;
    }

    /**
     * Set Class Arguments.
     * Sets the arguments for a specific class.
     *
     * @param string               $class_name Class name.
     * @param array<string, mixed> $args       Class functions arguments.
     *
     * @return bool
     */
    public function setClassArgs(string $class_name, array $args)
    : bool {
        if (class_exists($class_name)) {
            $name = $this->getIndexName($class_name);
            $this->classesArgs[$name] = $args;

            return true;
        }
        $this->results[] = array(
            self::ERROR_CLASS_NOT_FOUND,
            array("class" => $class_name)
        );

        return false;
    }

    /**
     * Get Index Name.
     * Returns a string that will be used as an index when referencing to this
     * class. In this case it's just the class name.
     *
     * @param string $class Class name.
     *
     * @return string
     */
    public function getIndexName(string $class)
    : string {
        return $class;
    }

    /**
     * Set Class.
     * Stores an already initialized class instance in the class container
     * array.
     *
     * @param object $class Class.
     *
     * @return void
     */
    public function setClass(object $class)
    : void {
        $name = $this->getIndexName(get_class($class));
        $this->classes[$name] = $class;
    }

    /**
     * Call Class Method.
     * Calls a class method.
     *
     * @param string               $class_name Class name.
     * @param string               $method     Method name.
     * @param array<string, mixed> $arguments  Arguments.
     *
     * @return mixed
     */
    public function callClassMethod(
        string $class_name,
        string $method,
        array $arguments = array()
    )
    : mixed {
        if ($this->hasClass($class_name)) {
            if (method_exists($class_name, $method)) {
                $class = $this->getClass($class_name);

                return $class->$method(...$arguments);
            }
            $this->results[] = array(
                self::ERROR_CLASS_METHOD_NOT_FOUND,
                array("class_name" => $class_name, "method_name" => $method)
            );
        } else {
            $this->results[] = array(
                self::ERROR_CLASS_NOT_FOUND_2,
                array("class_name" => $class_name)
            );
        }

        return null;
    }

    /**
     * Has Class.
     * Checks if the injector has a class.
     *
     * @param string $class_name Class name.
     *
     * @return bool
     */
    public function hasClass(string $class_name)
    : bool {
        if (empty($this->classes)) {
            return false;
        }
        $name = $this->getIndexName($class_name);

        return (array_key_exists($name, $this->classes));
    }

    /**
     * Set Class.
     * Stores an already initialized class instance in the class container
     * array.
     *
     * @param string $class_name Class name.
     * @param bool   $create     Create if class instance doesn't exist.
     *
     * @return mixed
     */
    public function getClass(string $class_name, bool $create = true)
    : mixed {
        if ($this->hasClass($class_name)) {
            $name = $this->getIndexName($class_name);

            return $this->classes[$name];
        }
        if ($create) {
            return $this->createClass($class_name);
        }

        return null;
    }

    /**
     * Create Class.
     * Creates a class.
     *
     * @param string $class_name Class name.
     * @param bool   $share      Share class: store among container known
     *                           instances.
     *
     * @return mixed
     */
    public function createClass(
        string $class_name,
        bool $share = true
    )
    : mixed {
        if (!class_exists($class_name)) {
            $this->results[] = array(
                self::ERROR_CLASS_NOT_FOUND_3,
                array("class_name" => $class_name)
            );

            return null;
        }
        try {
            $reflectedClass = new ReflectionClass($class_name);
            $dependencies = array();
            if ($reflectedClass->hasMethod("__construct")) {
                $constructor = $reflectedClass->getMethod("__construct");
                foreach ($constructor->getParameters() as $parameter) {
                    $arg_name = $parameter->getName();
                    $arg = $this->getClassArg($class_name, $arg_name);
                    if ($arg) {
                        $dependencies[] = $arg;
                    } elseif (!$parameter->isOptional()) {
                        $parameter_type = $parameter->getType();
                        if ($parameter_type === null) {
                            $this->results[] = array(
                                self::ERROR_CLASS_OR_PARAMETER_NOT_FOUND,
                                array(
                                    "class_name" => $class_name,
                                    "parameter_name" => $arg_name
                                )
                            );

                            return null;
                        }
                        if (!($parameter_type instanceof ReflectionNamedType)) {
                            $this->results[] = array(
                                self::ERROR_REFLECTION_PARAMETER,
                                array(
                                    "class_name" => $class_name,
                                    "parameter_name" => $arg_name
                                )
                            );

                            return null;
                        }
                        $dependency_class_name
                            = $parameter_type->getName();
                        $index
                            = $this->getIndexName($dependency_class_name);
                        if (!$this->hasClass($dependency_class_name)) {
                            $this->createClass($dependency_class_name);
                        }
                        $dependencies[] = $this->classes[$index];
                    }
                }
            }
            $name = $this->getIndexName($class_name);
            $class = new $class_name(...$dependencies);
            if ($share) {
                $this->classes[$name] = $class;
            }
            foreach ($this->helpers as $helper) {
                $helper[self::HELPER_INSTANCE]->{$helper[self::HELPER_METHOD_NAME]}(
                    $class,
                    $class_name
                );
            }

            return $class;
        } catch (ReflectionException $e) {
            $this->results[] = array(
                self::ERROR_CLASS_REFLECTION,
                array("message" => $e->getMessage())
            );

            return null;
        }
    }

    /**
     * Get Class Argument.
     * Returns a class arguments if there are any set.
     *
     * @param string $class_name Class name.
     * @param string $arg_name   Argument name.
     *
     * @return mixed
     */
    public function getClassArg(string $class_name, string $arg_name)
    : mixed {
        $name = $this->getIndexName($class_name);
        if (!isset($this->classesArgs[$name])) {
            return null;
        }
        if (!isset($this->classesArgs[$name][$arg_name])) {
            return null;
        }

        return $this->classesArgs[$name][$arg_name];
    }
}
