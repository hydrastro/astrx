<?php

declare(strict_types = 1);
/**
 * Class PostRedirectGet.
 */
class PostRedirectGet
{
    /**
     * Store.
     * Stores POST requests.
     *
     * @param string               $token Request token.
     * @param array<string, mixed> $data  Request data.
     *
     * @return void
     */
    public function store(string $token, array $data)
    : void {
        $_SESSION["POST_" . $token] = $data;
    }

    /**
     * Load.
     * Loads stored POST requests.
     *
     * @param string $token Request token.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $token)
    : mixed {
        if (!array_key_exists("POST_" . $token, $_SESSION)) {
            return array();
        }

        return $_SESSION["POST_" . $token];
    }
}