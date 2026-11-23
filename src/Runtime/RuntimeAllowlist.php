<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

use Devable\MaintenanceDnsBundle\Ip\IpAddressFilter;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;

/**
 * IPs of the configured hostnames of a sales channel, resolved at runtime (cached).
 */
class RuntimeAllowlist
{
    public function __construct(
        private readonly SalesChannelHosts $hosts,
        private readonly CachedHostResolver $resolver,
    ) {
    }

    /**
     * Returns the hostname that resolves to the client IP, or null. Stops resolving at the first match.
     */
    public function findMatchingHost(string $salesChannelId, string $clientIp): string|null
    {
        $clientIp = IpAddressFilter::normalize($clientIp);
        if (null === $clientIp) {
            return null;
        }

        foreach ($this->hosts->getHosts($salesChannelId) as $hostname) {
            if (\in_array($clientIp, $this->resolver->getIps($hostname), true)) {
                return $hostname;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function getIps(string $salesChannelId): array
    {
        $ips = [];
        foreach ($this->hosts->getHosts($salesChannelId) as $hostname) {
            \array_push($ips, ...$this->resolver->getIps($hostname));
        }

        return \array_values(\array_unique($ips));
    }
}
