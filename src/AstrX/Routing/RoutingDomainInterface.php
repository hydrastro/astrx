<?php

declare(strict_types = 1);

namespace AstrX\Routing;

interface RoutingDomainInterface
{
    /** Domain string used for loading <Domain>.routing.php */
    public function routingDomain()
    : string;
}