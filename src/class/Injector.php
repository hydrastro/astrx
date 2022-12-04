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
    public const ERROR_HELPER_REFLECTION = 0;
    public const ERROR_CLASS_NOT_FOUND = 1;
    public const ERROR_CLASS_NOT_FOUND_2 = 2;
    public const ERROR_CLASS_REFLECTION = 3;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    /**
     * @var array<string, object> $classes Injector container classes.
     */
    private array $classes = array();
    /**
     * @var array<string, array<string, mixed>> $classes_args Classes arguments.
     */
    private array $classes_args = array();
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
        assert(method_exists($helper_instance, $helper_method));

        try {
            $reflectedMethod = new ReflectionMethod(
                $helper_class_name, $helper_method
            );
            $parameters = $reflectedMethod->getParameters();
            $first_parameter = $parameters[0]->getType();
            $second_parameter = $parameters[1]->getType();
            assert($first_parameter instanceof ReflectionNamedType);
            assert($first_parameter->getName() === "object");
            assert($second_parameter instanceof ReflectionNamedType);
            assert($second_parameter->getName() === "string");
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
     * @return void
     */
    public function setClassArgs(string $class_name, array $args)
    : void {
        $name = $this->getIndexName($class_name);
        $this->classes_args[$name] = $args;
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
     * @param bool                 $create     Create class flag.
     *
     * @return mixed
     */
    public function callClassMethod(
        string $class_name,
        string $method,
        array $arguments = array(),
        bool $create = false
    )
    : mixed {
        if ($this->hasClass($class_name)) {
            assert(method_exists($class_name, $method));
            $class = $this->getClass($class_name);

            return $class->$method(...$arguments);
        }
        if ($create) {
            $this->createClass($class_name);

            return $this->callClassMethod($class_name, $method, $arguments);
        }

        // The class haven't been found and the caller doesn't even want to
        // create it...
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
     * @return Object|null
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
     * @return Object|null
     */
    public function createClass(
        string $class_name,
        bool $share = true
    )
    : mixed {
        if (!class_exists($class_name)) {
            $this->results[] = array(
                self::ERROR_CLASS_NOT_FOUND_2,
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
                    if ($arg !== null) {
                        $dependencies[] = $arg;
                    } elseif (!$parameter->isOptional()) {
                        $parameter_type = $parameter->getType();
                        assert($parameter_type !== null);
                        assert($parameter_type instanceof ReflectionNamedType);
                        $dependency_class_name
                            = $parameter_type->getName();
                        $dependency = $this->getClass($dependency_class_name);
                        $dependencies[] = $dependency;
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
    : mixed
    {
        $name = $this->getIndexName($class_name);

        if (!array_key_exists($name, $this->classes_args) ||
            !array_key_exists($arg_name, $this->classes_args[$name])) {
            return null;
        }

        return $this->classes_args[$name][$arg_name];
    }
}
