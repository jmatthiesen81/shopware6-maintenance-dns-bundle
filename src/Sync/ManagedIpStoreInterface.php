<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

/**
 * Persistent record of the IPs the bundle manages per sales channel.
 */
interface ManagedIpStoreInterface
{
    /**
     * @return array<string, ManagedIp> keyed by IP
     */
    public function load(string $salesChannelId): array;

    /**
     * @return list<string> sales channel IDs that have managed IPs
     */
    public function getSalesChannelIds(): array;

    /**
     * @param array<string, ManagedIp> $upserts
     * @param list<string>             $deletes IPs
     */
    public function apply(string $salesChannelId, array $upserts, array $deletes): void;

    /**
     * Forgets all managed IPs of the sales channel.
     */
    public function remove(string $salesChannelId): void;
}
