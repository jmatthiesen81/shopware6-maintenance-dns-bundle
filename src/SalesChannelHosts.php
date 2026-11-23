<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle;

/**
 * Configured mapping of sales channel IDs to the hostnames whose IPs are allowed during maintenance.
 */
final class SalesChannelHosts
{
    /**
     * @param array<string, list<string>> $hostsBySalesChannel normalized sales channel ID (lowercase hex) => hostnames
     */
    public function __construct(private readonly array $hostsBySalesChannel)
    {
    }

    /**
     * @return list<string>
     */
    public function getSalesChannelIds(): array
    {
        return \array_map('strval', \array_keys($this->hostsBySalesChannel));
    }

    public function has(string $salesChannelId): bool
    {
        return isset($this->hostsBySalesChannel[\strtolower($salesChannelId)]);
    }

    /**
     * @return list<string>
     */
    public function getHosts(string $salesChannelId): array
    {
        return $this->hostsBySalesChannel[\strtolower($salesChannelId)] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getAllHosts(): array
    {
        return \array_values(\array_unique(\array_merge(...\array_values($this->hostsBySalesChannel))));
    }
}
