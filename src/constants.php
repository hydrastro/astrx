<?php

declare(strict_types = 1);

define("INDEX_DIR", dirname(__FILE__) . DIRECTORY_SEPARATOR);
const CLASS_DIR = INDEX_DIR . "class" . DIRECTORY_SEPARATOR;
const CONTROLLER_DIR = INDEX_DIR . "controller" . DIRECTORY_SEPARATOR;
const PAGE_DIR = INDEX_DIR . "page" . DIRECTORY_SEPARATOR;
const LANG_DIR = INDEX_DIR . "lang" . DIRECTORY_SEPARATOR;
const CONFIG_DIR = INDEX_DIR . "config" . DIRECTORY_SEPARATOR;

// (debug, info, notice, warning, error, critical, alert, emergency).
const MESSAGE_LEVEL_ERROR = 0;
const MESSAGE_LEVEL_WARNING = 1;
const MESSAGE_LEVEL_INFO = 2;
const MESSAGE_HTTP_STATUS = 0;
const MESSAGE_LEVEL = 1;
const MESSAGE_TEXT = 2;

const DATA_DIR = INDEX_DIR . "data" . DIRECTORY_SEPARATOR;
const AVATAR_DIR = DATA_DIR . "avatar" . DIRECTORY_SEPARATOR;
const TEMPLATE_DIR = INDEX_DIR . "template" . DIRECTORY_SEPARATOR;
const FONT_DIR = INDEX_DIR . "font" . DIRECTORY_SEPARATOR;
const DEFAULT_AVATAR_DIR = AVATAR_DIR . "default" . DIRECTORY_SEPARATOR;

const INCLUDE_GUARD = true;
//define("BASE_URL", strtok($_SERVER["REQUEST_URI"], '?'));
