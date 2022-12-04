<?php

declare(strict_types = 1);

return array(
    "TemplateEngine" => array(
        "template_dir" => TEMPLATE_DIR,
        "template_extension" => ".html",
        "results_map" => array(
            TemplateEngine::ERROR_UNDEFINED_TOKEN_ARGUMENT => array(
                500,
                ERROR_UNDEFINED_TOKEN_ARGUMENT, // parent, args
                ErrorHandler::LOG_LEVEL_ERROR
            ),
            TemplateEngine::ERROR_INVALID_DEREFERENCE => array(
                500,
                ERROR_INVALID_DEREFERENCE, // value, args
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
