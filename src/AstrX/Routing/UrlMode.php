<?php

declare(strict_types = 1);

namespace AstrX\Routing;

enum UrlMode: string
{
    case QUERY = 'query';
    case REWRITE = 'rewrite';
}