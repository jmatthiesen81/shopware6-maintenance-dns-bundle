<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Sync;

use Devable\MaintenanceDnsBundle\Sync\ManagedIp;
use Devable\MaintenanceDnsBundle\Sync\ManagedIpStoreInterface;

/**
 * @internal
 */
final class InMemoryManagedIpStore implements ManagedIpStoreInterface
{
    public int $writes = 0;

    /**
     * @param array<string, array<string, ManagedIp>> $records
     */
    public function __construct(public array $records = [])
    {
    }

    public function load(string $salesChannelId): array
    {
        return $this->records[$salesChannelId] ?? [];
    }

    public function getSalesChannelIds(): array
    {
        return \array_map('strval', \array_keys(\array_filter($this->records)));
    }

    public function apply(string $salesChannelId, array $upserts, array $deletes): void
    {
        ++$this->writes;

        foreach ($deletes as $ip) {
            unset($this->records[$salesChannelId][$ip]);
        }

        foreach ($upserts as $ip => $record) {
            $this->records[$salesChannelId][$ip] = $record;
        }

        \ksort($this->records[$salesChannelId]);

        if ([] === $this->records[$salesChannelId]) {
            unset($this->records[$salesChannelId]);
        }
    }

    public function remove(string $salesChannelId): void
    {
        ++$this->writes;
        unset($this->records[$salesChannelId]);
    }
}
