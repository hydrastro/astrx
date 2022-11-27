<?php

declare(strict_types = 1);

const ERROR_TEMPLATE_FILE_NOT_FOUND = "Error: template file '{{template_file}}' for template '{template}' not found.";
const ERROR_INVALID_PARSE_MODE = "Invalid parse mode.";
const ERROR_MALFORMED_TAG_CHANGE = "Error: malformed tag change.";
const ERROR_UNCLOSED_TOKEN = "Error: unclosed token detected.";
const ERROR_LOOP_TOKEN_MISMATCH = "Error: template tokens mismatch. Opening tag: '{{opening_tag}}', closing tag: '{{closing_tag}}'";
const ERROR_UNCLOSED_LOOP_TOKEN = "An error occurred, unclosed token(s) found: '{{unclosed tokens}}'.";
const ERROR_TEMPLATE_CLASS_CREATION = "An error occurred while creating the class for the template.";
const ERROR_INVALID_DEREFERENCE = "An error occurred while dereferencing a variable with value '{value}' and arguments '{{args}}'.";
const ERROR_TEMPLATE_AST_INCONSISTENCY = "A consistency error occurred while processing the template abstract syntax tree.";
const ERROR_UNDEFINED_TOKEN_ARGUMENT = "Error: undefined token argument. Parent: '{parent}', arguments: '{{args}}'";
const ERROR_TEMPLATE_EVALUATION = "An error occurred while evaluating the template. More details: '{{message}}'";
