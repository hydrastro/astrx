<?php

declare(strict_types = 1);
define(
    "INDEX_DIR",
    realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR
);
const RESOURCES_DIR = INDEX_DIR . "resources" . DIRECTORY_SEPARATOR;
const LANG_DIR = RESOURCES_DIR . "lang" . DIRECTORY_SEPARATOR;
const CONFIG_DIR = RESOURCES_DIR . "config" . DIRECTORY_SEPARATOR;
const TEMPLATE_DIR = RESOURCES_DIR . "template" . DIRECTORY_SEPARATOR;
const TEMPLATE_CACHE_DIR = TEMPLATE_DIR . "cache" . DIRECTORY_SEPARATOR;

set_include_path(__DIR__);

require INDEX_DIR . "src" . DIRECTORY_SEPARATOR . "bootstrap.php";
