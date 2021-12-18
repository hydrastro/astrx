<?php

class Request
{
    /**
     * Request constructor.
     */
    public function __construct()
    {
    }

    /**
     * Get.
     * Returns a $_GET value.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key)
    : mixed {
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return null;
    }

    /**
     * Post.
     * Returns a $_POST value.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function post(string $key)
    : mixed {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        return null;
    }

    /**
     * Files.
     * Returns a $_FILES value.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function files(string $key)
    : mixed {
        if (isset($_FILES[$key])) {
            return $_FILES[$key];
        }

        return null;
    }

    /**
     * Request.
     * Returns a $_REQUEST value.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function request(string $key)
    : mixed {
        if (isset($_REQUEST[$key])) {
            return $_REQUEST[$key];
        }

        return null;
    }
}
