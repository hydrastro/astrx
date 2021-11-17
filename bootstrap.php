<?php
define("DEBUG_MODE", true);
define("INCLUDE_GUARD", true);
define("INDEX_DIR", __DIR__);
define("CONFIG_DIR", INDEX_DIR . "/config/");
define("BASE_URL", strtok($_SERVER["REQUEST_URI"], '?'));
define("PAGE_DIR", INDEX_DIR . "/page/");
define("CLASS_DIR", INDEX_DIR . "/class/");
define("CONTROLLER_DIR", INDEX_DIR . "/controller/");
define("LANG_DIR", INDEX_DIR . "/lang/");
define("DATA_DIR", INDEX_DIR . "/lang/");
define("AVATAR_DIR", DATA_DIR . "/avatar/");
define("TEMPLATE_DIR", INDEX_DIR . "/template/");
define("FONT_DIR", INDEX_DIR . "/font/");
define("DEFAULT_AVATAR_DIR", AVATAR_DIR . "/default/");

require(CLASS_DIR . "error_handler.php");
require(CLASS_DIR . "config.php");
require(CLASS_DIR . "autoloader.php");

// ErrorHandler, config, injector
$ErrorHandler = new ErrorHandler();
$config = new Config();
$ErrorHandler->addClass($config);
new Autoloader($config);
$injector = new Injector($config, $ErrorHandler);
