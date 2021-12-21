<?php

/**
 * Class Autoloader
 */
class Autoloader
{
    /**
     * Autoloader constructor.
     */
    public function __construct()
    {
        spl_autoload_register(array($this, "classAutoload"));
    }

    /**
     * Class autoloader function.
     * This function auto-loads the project's classes among their configs
     * (language files, constants, configuration variables).
     *
     * @param string $class Class name to autoload.
     *
     * @return void
     */
    public function classAutoload(string $class)
    {
        $class_dir = (strpos(strtolower($class), "controller")) ?
            CONTROLLER_DIR : CLASS_DIR;
        $class = toSnakeCase($class);
        $class_file = "$class_dir$class.php";
        if (file_exists($class_file)) {
            include_once $class_file;
        }
    }
}
