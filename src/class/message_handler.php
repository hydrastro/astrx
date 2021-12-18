<?php

class MessageHandler
{
    /**
     * @var array<int, object> classes Classes.
     */
    private array $classes = array();
    /**
     * @var array<int, array<mixed>> $messages Messages array.
     */
    public array $messages = array();
    /**
     * @var array<int, Throwable> $exceptions Exceptions array.
     */
    public array $exceptions = array();

    /**
     * MessageHandler constructor.
     */
    public function __construct()
    {
        $this->classes[] = $this;
    }

    /**
     * Add Class.
     * Adds a class to the class array.
     *
     * @param object $class
     *
     * @return void
     */
    public function addClass(object $class)
    {
        $this->classes[] = $class;
    }

    /**
     * Remove Class.
     * Removes a class from the class array.
     *
     * @param string $class_name
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
     * Get Messages.
     * Retrieves and returns the messages of all the classes handled.
     * @return array<int, array<int, mixed>>
     */
    public function getMessages()
    : array
    {
        $messages = array();
        if (empty($this->classes)) {
            return array();
        }
        foreach ($this->classes as $class) {
            if (property_exists($class, "messages") &&
                is_array($class->messages)) {
                foreach ($class->messages as $message) {
                    $messages[] = $message;
                }
            }
        }

        return $messages;
    }
}
