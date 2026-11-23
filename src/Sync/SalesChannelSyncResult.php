<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

use Devable\MaintenanceDnsBundle\Dns\DnsResult;

final class SalesChannelSyncResult
{
    /**
     * @param array<string, DnsResult> $dnsResults keyed by hostname
     */
    public function __construct(
        public readonly string $salesChannelId,
        public readonly array $dnsResults,
        public readonly SyncPlan|null $plan,
        public readonly string|null $error = null,
    ) {
    }

    public function hasDnsProblems(): bool
    {
        foreach ($this->dnsResults as $result) {
            if (!$result->isSuccess()) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        return null !== $this->plan && [] !== $this->plan->warnings;
    }
}
