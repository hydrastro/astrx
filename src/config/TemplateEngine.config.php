<?php

declare(strict_types = 1);

return array(
    "TemplateEngine" => array(
        "template_dir" => TEMPLATE_DIR,
        "results_map" => array(
            TemplateEngine::ERROR_INVALID_PARSE_MODE => array(
                500,
                ERROR_INVALID_PARSE_MODE,
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_TEMPLATE_CLASS_CREATION => array(
                500,
                ERROR_TEMPLATE_CLASS_CREATION,
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_TEMPLATE_AST_INCONSISTENCY => array(
                500,
                ERROR_TEMPLATE_AST_INCONSISTENCY,
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_UNDEFINED_TOKEN_ARGUMENT => array(
                500,
                ERROR_UNDEFINED_TOKEN_ARGUMENT, // parent, args
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_UNDEFINED_TOKEN_ARGUMENT_2 => array(
                500,
                ERROR_UNDEFINED_TOKEN_ARGUMENT, // parent, args
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_INVALID_DEREFERENCE => array(
                500,
                ERROR_INVALID_DEREFERENCE, // value, args
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_TEMPLATE_FILE_NOT_FOUND => array(
                500,
                ERROR_TEMPLATE_FILE_NOT_FOUND, // template, template_file
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_MALFORMED_TAG_CHANGE => array(
                500,
                ERROR_MALFORMED_TAG_CHANGE,
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_UNCLOSED_TOKEN => array(
                500,
                ERROR_UNCLOSED_TOKEN,
                ErrorHandler::LOG_LEVEL_ERROR
            ),

            TemplateEngine::ERROR_MALFORMED_TAG_CHANGE_2 => array(
                500,
                ERROR_MALFORMED_TAG_CHANGE,
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_UNCLOSED_TOKEN_2 => array(
                500,
                ERROR_UNCLOSED_TOKEN,
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_LOOP_TOKEN_MISMATCH => array(
                500,
                ERROR_LOOP_TOKEN_MISMATCH, // opening_tag, closing_tag
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_UNCLOSED_LOOP_TOKEN => array(
                500,
                ERROR_UNCLOSED_LOOP_TOKEN, // unclosed_tokens
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_TEMPLATE_EVALUATION => array(
                500,
                ERROR_TEMPLATE_EVALUATION, // message
                ErrorHandler::LOG_LEVEL_ERROR
            )
        )
    )
);
