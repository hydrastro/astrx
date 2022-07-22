<?php

declare(strict_types = 1);
/**
 * Class Request.
 */
class Request
{
    /**
     * Request Constructor.
     */
    public function __construct()
    {
    }

    /**
     * Get.
     * Returns a $_GET value.
     *
     * @param string $key Key.
     *
     * @return mixed
     */
    public function get(string $key, mixed $fallback = null)
    : mixed {
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $fallback;
    }

    /**
     * Post.
     * Returns a $_POST value.
     *
     * @param string $key Key.
     *
     * @return mixed
     */
    public function post(string $key, mixed $fallback = null)
    : mixed {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        return $fallback;
    }

    /**
     * Files.
     * Returns a $_FILES value.
     *
     * @param string $key Key.
     *
     * @return mixed
     */
    public function files(string $key, mixed $fallback = null)
    : mixed {
        if (isset($_FILES[$key])) {
            return $_FILES[$key];
        }

        return $fallback;
    }

    /**
     * Request.
     * Returns a $_REQUEST value.
     *
     * @param string $key Key.
     *
     * @return mixed
     */
    public function request(string $key, mixed $fallback = null)
    : mixed {
        if (isset($_REQUEST[$key])) {
            return $_REQUEST[$key];
        }

        return $fallback;
    }
}
