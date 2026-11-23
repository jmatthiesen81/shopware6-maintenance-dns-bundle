<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

enum DnsStatus: string
{
    /**
     * The name server answered with at least one usable address.
     */
    case Success = 'success';

    /**
     * The name server answered authoritatively that the name does not exist.
     */
    case NxDomain = 'nxdomain';

    /**
     * The name exists, but has no (acceptable) address of the queried types.
     */
    case NoData = 'nodata';

    /**
     * Timeout, SERVFAIL, REFUSED, broken or truncated answer – a temporary or unknown error.
     */
    case Failure = 'failure';

    /**
     * A definitive negative answer, as opposed to a temporary failure.
     */
    public function isNegativeAnswer(): bool
    {
        return self::NxDomain === $this || self::NoData === $this;
    }
}
