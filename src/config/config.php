<?php

declare(strict_types = 1);

use AstrX\EnvironmentType;

return [
    "Prelude" => [
        "environment" => EnvironmentType::DEVELOPMENT->value,
        "available_languages" => ["en", "it"]
    ]
];
