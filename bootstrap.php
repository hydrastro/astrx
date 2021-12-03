<?php
require(INDEX_DIR . "constants.php");
require(INDEX_DIR . "functions.php");
require(CLASS_DIR . "autoloader.php");

new Autoloader();
$injector = new Injector();
$injector->createClass("ContentManager");
