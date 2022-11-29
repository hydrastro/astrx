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
    // System is unusable.
    public const LOG_LEVEL_EMERGENCY = 7;
    // Action must be taken immediately.
    public const LOG_LEVEL_ALERT = 6;
    // Critical conditions.
    public const LOG_LEVEL_CRITICAL = 5;
    // Runtime errors that do not require immediate action but should
    // typically be logged and monitored.
    public const LOG_LEVEL_ERROR = 4;
    // Exceptional occurrences that are not errors.
    public const LOG_LEVEL_WARNING = 3;
    // Normal but significant events.
    public const LOG_LEVEL_NOTICE = 2;
    // Interesting events.
    public const LOG_LEVEL_INFO = 1;
    // Detailed debug information.
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
     * @var array<string, array<int, array<int, mixed>>> $results_maps Class
     * results maps, used for interpolation and better display.
     */
    private array $results_maps = array();

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
        // TODO: check assertions.
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
                ini_set("display_errors", "0");
                ini_set("display_startup_errors", "1");
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
        $exceptions = $this->exceptions;
        if ($exceptions === array()) {
            return;
        }

        http_response_code(500);
        $failsafe = TEMPLATE_DIR . "failsafe.php";
        $messages = $this->getResultsMessages();
        if (file_exists($failsafe)) {
            require($failsafe);
        } else {
            // TODO: better html here.
            echo "<h1>Error</h1><pre>";
            print_r($messages);
            print_r($exceptions);
        }
    }

    /**
     * Get Results Messages.
     * Returns interpolated and fixed to the current log level classes'
     * result messages.
     * @return array<int, string>
     */
    public function getResultsMessages()
    : array
    {
        /*
         * results:
         * [
         *   class_name =>
         *   [
         *     [
         *       error code,
         *       [ interpolation args ]
         *     ],
         *     [
         *       error code,
         *       [ interpolation args ]
         *     ]
         *   ]
         * ]
         *
         * results_map:
         * [
         *   class_name =>
         *   [
         *     error code =>
         *     [
         *       http status code,
         *       text,
         *       log level
         *     ],
         *     error code =>
         *     [
         *       http status code,
         *       text,
         *       log level
         *     ]
         *   ]
         * ]
         */
        $results = $this->getResults();
        $results_map = $this->results_maps;
        $messages = array();
        // Checking if the map that links the results to their own
        // language constants is loaded or not, and if not, we just
        // display their information as good as we can in english.
        if ($results_map === array()) {
            // This branch is
            foreach ($results as $class_name => $class_results) {
                foreach ($class_results as $class_result) {
                    $message
                        = "An error occurred on class '$class_name'. ";
                    $message .= "Error code: '" . $class_result[0] . "', ";
                    $message .= "interpolation arguments: '" .
                                print_r($class_result[1], true) .
                                "'.";
                    $messages[] = $message;
                }
            }
        } else {
            foreach ($results as $class_name => $class_results) {
                foreach ($class_results as $class_result) {
                    $result_map = $results_map[$class_name][$class_result[0]];
                    if ($this->log_level > $result_map[3]) {
                        continue;
                    }
                    if (is_string($result_map[1]) &&
                        is_array($class_result[1])) {
                        $messages[] = $this->interpolate(
                            $result_map[1],
                            $class_result[1]
                        );
                    } else {
                        $messages[]
                            = "An error occurred and another error occurred while trying to display the previous error";
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Get Results.
     * Retrieves and returns the results of all the handled classes.
     * @return array<string, array<int, array<int, mixed>>>
     */
    public function getResults()
    : array
    {
        $results = array();
        if ($this->classes === array()) {
            return array();
        }
        foreach ($this->classes as $class) {
            if (property_exists($class, 'results') &&
                is_array($class->results) &&
                !($class->results === array())) {
                assert(is_string(get_class($class)));
                $results[get_class($class)] = $class->results;
            }
        }

        /**
         * @var array<string, array<int, array<int, mixed>>> $results
         */
        return $results;
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

    /**
     * Add Results Map.
     * Add results map used for interpolating class result messages.
     *
     * @param string                        $class_name Class name.
     * @param array<int, array<int, mixed>> $map        Results map.
     *
     * @return void
     */
    public function addResultsMap(string $class_name, array $map)
    : void {
        $this->results_maps[$class_name] = $map;
    }

    /**
     * Add Multiple Results Maps.
     * Adds multiple results maps of different classes.
     *
     * @param array<string, array<int, array<int, mixed>>> $maps Result maps.
     *
     * @return void
     */
    public function addMultipleResultsMaps(array $maps)
    : void {
        foreach ($maps as $class_name => $class_map) {
            $this->addResultsMap($class_name, $class_map);
        }
    }

    /**
     * Interpolate.
     * Interpolates context values into the message placeholders.
     *
     * @param string               $message Message.
     * @param array<string, mixed> $context Context.
     *
     * @return string
     */
    public function interpolate(string $message, array $context = array())
    : string {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            // check that the value can be cast to string
            if (!is_array($val) &&
                (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
