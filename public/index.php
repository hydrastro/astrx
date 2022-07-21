<?php

declare(strict_types = 1);

const INDEX_DIR
= __DIR__ .
  DIRECTORY_SEPARATOR .
  ".." .
  DIRECTORY_SEPARATOR .
  "src" .
  DIRECTORY_SEPARATOR;
const CLASS_DIR = INDEX_DIR . "class" . DIRECTORY_SEPARATOR;
const LANG_DIR = INDEX_DIR . "lang" . DIRECTORY_SEPARATOR;
const CONFIG_DIR = INDEX_DIR . "config" . DIRECTORY_SEPARATOR;
const TEMPLATE_DIR = INDEX_DIR . "template" . DIRECTORY_SEPARATOR;
const CONTROLLER_DIR = INDEX_DIR . "controller" . DIRECTORY_SEPARATOR;

set_include_path(__DIR__);

require INDEX_DIR . "bootstrap.php";
