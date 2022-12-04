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
        "default_language" => "en",
        "language_catastrophe_message" => "Error: no language file could be loaded.",
        "main_page_id" => "main",
        "default_template" => "default",
        "website_name" => "AstrX"
    ),
    "UrlHandler" => array(
        "base_path" => "/",
        "entry_point" => "index.php",
        "url_rewrite" => true,
        "current_page_parameters_config" => array(
            "language_parameter_name",
            "page_id_parameter_name"
        ),
        "parameters_map" => array(
            "language_parameter_name" => "lang",
            "page_id_parameter_name" => "id"
        )
    )
);
