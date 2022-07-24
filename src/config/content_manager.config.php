<?php

declare(strict_types = 1);

return array(
    "PDO" => array(
        "db_type" => "mysql",
        "db_host" => "172.19.0.1",
        "db_name" => "content_manager",
        "db_username" => "user",
        "db_password" => "password"
    ),
    "ContentManager" => array(
        "language_parameter_name" => "lang", // null for disabling the language
        // parameter
        "page_id_parameter_name" => "id",
        "current_page_parameters_config" => array(
            "language_parameter_name",
            "page_id_parameter_name"
        ),
        "url_rewrite" => true,
        "default_language" => "en",
        "base_path" => "",
        "language_catastrophe_message" => "Error: no language file could be loaded.",
        "main_page_id" => "main"
    )
);
