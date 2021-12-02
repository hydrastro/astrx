<?php
define("DEBUG_MODE", true);
define("MESSAGE_LEVEL_ERROR", 0);
define("MESSAGE_LEVEL_WARNING", 1);
define("MESSAGE_LEVEL_INFO", 2);
define("INCLUDE_GUARD", true);
define("INDEX_DIR", __DIR__ . DIRECTORY_SEPARATOR);
define("CONFIG_DIR", INDEX_DIR . "config/");
define("BASE_URL", strtok($_SERVER["REQUEST_URI"], '?'));
define("PAGE_DIR", INDEX_DIR . "page/");
define("CLASS_DIR", INDEX_DIR . "class/");
define("CONTROLLER_DIR", INDEX_DIR . "controller/");
define("LANG_DIR", INDEX_DIR . "lang/");
define("DATA_DIR", INDEX_DIR . "data/");
define("AVATAR_DIR", DATA_DIR . "avatar/");
define("TEMPLATE_DIR", INDEX_DIR . "template/");
define("FONT_DIR", INDEX_DIR . "font/");
define("DEFAULT_AVATAR_DIR", AVATAR_DIR . "default/");

require("functions.php");

require(CLASS_DIR . "error_handler.php");
require(CLASS_DIR . "config.php");
require(CLASS_DIR . "autoloader.php");
require(CLASS_DIR . "injector.php");

$injector = new Injector(new ErrorHandler(), new Config());
$injector->createClass("ContentManager");
