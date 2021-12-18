<?php

declare(strict_types = 1);
define("INDEX_DIR", dirname(__FILE__) . DIRECTORY_SEPARATOR);
ini_set("display_errors", "1");
ini_set("display_startup_errors", "1");
error_reporting(E_ALL);
require(INDEX_DIR . "constants.php");
require(INDEX_DIR . "functions.php");
require(CLASS_DIR . "autoloader.php");

new Autoloader();
$injector = new Injector();
//$injector->createClass("ContentManager");
$injector->createClass("TemplateEngine");
