<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class ErrorHandler.
 */
class ErrorHandler
{
    public const ENVIRONMENT_DEVELOPMENT = 0;
    public const ENVIRONMENT_PRODUCTION = 1;
    public const ENVIRONMENT_TESTING = 2;
    public const ENVIRONMENT_STAGING = 3;
    public const LOG_LEVEL_EMERGENCY = 7;
    public const LOG_LEVEL_ALERT = 6;
    public const LOG_LEVEL_CRITICAL = 5;
    public const LOG_LEVEL_ERROR = 4;
    public const LOG_LEVEL_WARNING = 3;
    public const LOG_LEVEL_NOTICE = 2;
    public const LOG_LEVEL_INFO = 1;
    public const LOG_LEVEL_DEBUG = 0;
    public const ERROR_UNDEFINED_ENVIRONMENT = 1;
    public const ERROR_CLASS_TO_REMOVE_NOT_FOUND = 0;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    /**
     * @var int $log_level Log level.
     */
    public int $log_level = self::LOG_LEVEL_DEBUG;
    /**
     * @var array<int, Throwable> $exceptions Uncaught exceptions (objects).
     */
    protected array $exceptions = array();
    /**
     * @var array<int, object> classes Classes.
     */
    private array $classes = array();

    /**
     * ErrorHandler Constructor.
     */
    public function __construct()
    {
        set_error_handler(array($this, "errorHandler"));
        set_exception_handler(array($this, "exceptionsHandler"));
        register_shutdown_function(array($this, "shutdownHandler"));
        $this->classes[] = $this;
    }

    /**
     * Set Environment.
     * Sets the environment.
     *
     * @param int $environment Environment.
     *
     * @return void
     */
    public function setEnvironment(int $environment)
    : void {
        switch ($environment) {
            default:
                $this->results[] = array(
                    self::ERROR_UNDEFINED_ENVIRONMENT
                );
            case self::ENVIRONMENT_TESTING:
            case self::ENVIRONMENT_DEVELOPMENT:
                ini_set("display_errors", "1");
                ini_set("display_startup_errors", "1");
                error_reporting(E_ALL);
                $this->log_level = self::LOG_LEVEL_DEBUG;
                break;
            case self::ENVIRONMENT_STAGING:
            case self::ENVIRONMENT_PRODUCTION:
                error_reporting(E_ALL & ~E_NOTICE);
                $this->log_level = self::LOG_LEVEL_ERROR;
                break;
        }
    }

    /**
     * Exceptions Handler.
     * Handles exceptions.
     *
     * @param Throwable $e Throwable.
     *
     * @return void
     */
    public function exceptionsHandler(Throwable $e)
    : void {
        $this->exceptions[] = $e;
    }

    /**
     * Error Handler.
     * Throws errors as exceptions.
     *
     * @param int    $errno   Error number.
     * @param string $errstr  Error string.
     * @param string $errfile Error file.
     * @param int    $errline Error line.
     *
     * @return bool
     */
    public function errorHandler(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline
    )
    : bool {
        $level = error_reporting();
        if (($level & $errno) === 0) {
            return false;
        }
        $e = new ErrorException($errstr, 0, $errno, $errfile, $errline);
        $this->exceptions[] = $e;

        return true;
    }

    /**
     * Shutdown handler.
     * Called when a script dies, either naturally or due to a fatal error,
     * this function handles and displays possible occurred errors and
     * exceptions.
     * It's registered with register_shutdown_function.
     * @return void
     */
    public function shutdownHandler()
    : void
    {
        $exceptions = $this->getExceptions();
        if (!empty($exceptions)) {
            http_response_code(HTTP_INTERNAL_SERVER_ERROR);
            $failsafe = TEMPLATE_DIR . "failsafe.php";
            if (file_exists($failsafe)) {
                require($failsafe);
            } else {
                // TODO: better html here.
                echo "<h1>Error</h1><pre>";
                print_r($exceptions);
            }
        }
    }

    /**
     * Get Exceptions.
     * Retrieves and returns the exceptions of all the handled classes.
     * @return array<int, Throwable>
     */
    public function getExceptions()
    : array
    {
        $exceptions = array();
        if (empty($this->classes)) {
            return array();
        }
        foreach ($this->classes as $class) {
            if (property_exists($class, 'exceptions') &&
                is_array($class->exceptions)) {
                foreach ($class->exceptions as $exception) {
                    $exceptions[] = $exception;
                }
            }
        }

        return $exceptions;
    }

    /**
     * Add Class.
     * Adds a class to the class array.
     *
     * @param object $class_instance Class.
     * @param string $_class_name    Class name.
     *
     * @return void
     * @noinspection PhpUnusedParameterInspection
     */
    public function addClass(object $class_instance, string $_class_name = "")
    : void {
        $this->classes[] = $class_instance;
    }

    /**
     * Remove Class.
     * Removes a class from the class array.
     *
     * @param string $class_name Class name.
     *
     * @return bool
     */
    public function removeClass(string $class_name)
    : bool {
        foreach ($this->classes as $key => $class) {
            if (get_class($class) === $class_name) {
                unset($this->classes[$key]);

                return true;
            }
        }
        $this->results[] = array(
            self::ERROR_CLASS_TO_REMOVE_NOT_FOUND,
            array("class_name" => $class_name)
        );

        return false;
    }
}
