<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

final class SyncPlan
{
    /**
     * @param list<string>|null        $allowlist     the new allowlist (null = empty)
     * @param list<string>             $added         IPs the bundle adds to the allowlist
     * @param list<string>             $removed       IPs the bundle removes from the allowlist
     * @param array<string, ManagedIp> $upserts       managed IP records to insert or update
     * @param list<string>             $deletes       managed IP records to delete
     * @param list<string>             $warnings
     */
    public function __construct(
        public readonly string $salesChannelId,
        public readonly array|null $allowlist,
        public readonly bool $allowlistChanged,
        public readonly array $added,
        public readonly array $removed,
        public readonly array $upserts,
        public readonly array $deletes,
        public readonly array $warnings,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->allowlistChanged || [] !== $this->upserts || [] !== $this->deletes;
    }
}
