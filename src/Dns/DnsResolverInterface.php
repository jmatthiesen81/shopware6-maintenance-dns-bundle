<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

interface DnsResolverInterface
{
    /**
     * Resolves a configured hostname. Never throws for DNS problems, they are reported as DnsResult status.
     */
    public function resolve(string $hostname): DnsResult;
}
