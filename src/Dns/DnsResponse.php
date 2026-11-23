<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

final class DnsResponse
{
    /**
     * @param list<string> $addresses raw, not yet validated addresses of the queried name
     */
    public function __construct(
        public readonly int $rcode,
        public readonly bool $truncated,
        public readonly array $addresses,
    ) {
    }
}
