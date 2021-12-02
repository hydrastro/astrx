<?php
define("DEBUG_MODE", true);
define("MESSAGE_LEVEL_ERROR", 0);
define("MESSAGE_LEVEL_WARNING", 1);
define("MESSAGE_LEVEL_INFO", 2);
define("INCLUDE_GUARD", true);
define("BASE_URL", strtok($_SERVER["REQUEST_URI"], '?'));
define("DS", DIRECTORY_SEPARATOR);
define("INDEX_DIR", __DIR__ . DIRECTORY_SEPARATOR);
define("CONFIG_DIR", INDEX_DIR . "config" . DS);
define("PAGE_DIR", INDEX_DIR . "page" . DS);
define("CLASS_DIR", INDEX_DIR . "class" . DS);
define("CONTROLLER_DIR", INDEX_DIR . "controller" . DS);
define("LANG_DIR", INDEX_DIR . "lang" . DS);
define("DATA_DIR", INDEX_DIR . "data" . DS);
define("AVATAR_DIR", DATA_DIR . "avatar" . DS);
define("TEMPLATE_DIR", INDEX_DIR . "template" . DS);
define("FONT_DIR", INDEX_DIR . "font" . DS);
define("DEFAULT_AVATAR_DIR", AVATAR_DIR . "default" . DS);

require("functions.php");

require(CLASS_DIR . "error_handler.php");
require(CLASS_DIR . "config.php");
require(CLASS_DIR . "autoloader.php");
require(CLASS_DIR . "injector.php");

$injector = new Injector(new ErrorHandler(), new Config());
$injector->createClass("ContentManager");
