<?php
/**
 * To Snake Case.
 * Converts a camelCase string to snake_case.
 *
 * @param $string
 *
 * @return string|null
 */
function toSnakeCase($string) {
	if(!is_string($string)) {
		return null;
	}

	return ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/',
		'_$0',
		$string)),
		'_');
}
