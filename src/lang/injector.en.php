<?php

declare(strict_types = 1);

const ERROR_CLASS_NOT_FOUND = "Error: the required class '{class_name}' was not found.";
const ERROR_CLASS_METHOD_NOT_FOUND = "Error: the class method '{method_name}' was not found for class '{class_name}'.";
const ERROR_CLASS_REFLECTION = "An error occurred while reflecting the class '{class_name}' while handling dependency injection.";
const ERROR_CLASS_OR_PARAMETER_NOT_FOUND = "Error: the required class '{class_name}' or parameter '{parameter_name}' was not found.";
const ERROR_HELPER_METHOD_NOT_FOUND = "Error: helper method '{method_name}' of the class '{class_name}' was not found.";
const ERROR_INVALID_HELPER_METHOD = "Error: invalid helper method '{method_name} of the class '{class_name}' provided.";
const ERROR_METHOD_REFLECTION = "An error occurred while reflecting the method '{method_name}' of the class '{class_name}' while handling dependency injection.";
