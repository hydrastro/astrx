<?php

/**
 * Class ErrorHandler.
 */
class ErrorHandler
{
    /**
     * @var array<int, object> classes Classes.
     */
    private array $classes = array();
    /**
     * @var array<int, array<int, mixed>> $messages Messages array.
     */
    public array $messages = array();
    /**
     * @var array<int, Throwable> $exceptions Exceptions (objects).
     */
    protected array $exceptions = array();

    /**
     * ErrorHandler Constructor.
     */
    public function __construct()
    {
        //todo: wrap these lines into debug
        ini_set("display_errors", "1");
        ini_set("display_startup_errors", "1");
        error_reporting(E_ALL);
        set_error_handler(array($this, "errorHandler"));
        set_exception_handler(array($this, "exceptionsHandler"));
        register_shutdown_function(array($this, "shutdownHandler"));
        $this->classes[] = $this;
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
    {
        $this->exceptions[] = $e;
        $this->messages[] = array(
            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
            MESSAGE_TEXT => $e->getMessage()
        );
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
        $error_code = HTTP_INTERNAL_SERVER_ERROR;
        $message_level = MESSAGE_LEVEL_ERROR;
        $this->messages[] = array(
            MESSAGE_LEVEL => $message_level,
            MESSAGE_HTTP_STATUS => $error_code,
            MESSAGE_TEXT => $e->getMessage()
        );

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
     * Add Class.
     * Adds a class to the class array.
     *
     * @param object $class_instance Class.
     *
     * @return void
     */
    public function addClass(object $class_instance)
    {
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
        $e = new Exception(ERROR_INVALID_ARRAY_INDEX);
        $this->exceptions[] = $e;
        $this->messages[] = array(
            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
            MESSAGE_TEXT => $e->getMessage()
        );

        return false;
    }

    /**
     * Get Exceptions.
     * Retrieves and returns the exceptions of all the classes handled.
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
}
