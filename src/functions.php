<?php

/**
 * @param string $string
 *
 * @return string
 */
function toSnakeCase(string $string)
: string {
    $temp = preg_replace(
        '/[A-Z]([A-Z](?![a-z]))*/',
        '_$0',
        $string
    );
    $temp = ($temp) ?: "";

    return ltrim(
        strtolower($temp),
        '_'
    );
}
