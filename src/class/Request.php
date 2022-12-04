<?php

declare(strict_types = 1);
/**
 * Class Request.
 */
class Request
{
    public string $ip;

    /**
     * Request Constructor.
     */
    public function __construct()
    {
        $this->ip = $this->getIp();
    }

    /**
     * Get Ip.
     * Returns the requests IP address.
     *
     * @param bool $trust_client Trust the client flag. Note: the client may
     *                           spoof its IP address, the only thing that is
     *                           certain here is REMOTE_ADDR.
     *
     * @return string
     */
    public function getIp(bool $trust_client = false)
    : string {
        if (array_key_exists("REMOTE_ADDR", $_SERVER)) {
            $current_ip = $_SERVER["REMOTE_ADDR"];
        } else {
            $current_ip = "0.0.0.0";
        }
        if (!$trust_client) {
            return $current_ip;
        }
        foreach (
            array(
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_X_CLUSTER_CLIENT_IP',
                'HTTP_FORWARDED_FOR',
                'HTTP_FORWARDED',
                'REMOTE_ADDR'
            ) as $key
        ) {
            if (array_key_exists($key, $_SERVER)) {
                foreach (array_map('trim', explode(',', $_SERVER[$key])) as $ip)
                {
                    if (filter_var(
                            $ip,
                            FILTER_VALIDATE_IP,
                            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                        ) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return $current_ip;
    }

    /**
     * Get.
     * Returns a $_GET value.
     *
     * @param string $key Key.
     * @param mixed  $fallback
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
     * @param mixed  $fallback
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
     * @param mixed  $fallback
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
     * @param mixed  $fallback
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
