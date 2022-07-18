<?php

declare(strict_types = 1);

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

/*
isNonEmptyString
getIp
convertTime
array_keys_kesists
isValidRegexArray
checkRegexFilter
isRegularExpression
*/
