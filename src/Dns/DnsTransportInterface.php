<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

interface DnsTransportInterface
{
    /**
     * Sends a query to the name server and returns the raw response with the same message ID.
     *
     * @throws DnsProtocolException on timeout or network errors
     */
    public function query(string $nameserver, string $packet, float $timeoutSeconds): string;
}
