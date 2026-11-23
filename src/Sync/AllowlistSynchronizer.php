<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

use Devable\MaintenanceDnsBundle\Dns\DnsResolverInterface;
use Devable\MaintenanceDnsBundle\Dns\DnsResult;
use Devable\MaintenanceDnsBundle\Runtime\CachedHostResolver;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Persistent mode: resolves the configured hostnames and synchronizes the managed IPs into the allowlist.
 *
 * DNS lookups run before the transaction, so slow name servers never hold a database lock. Reading, planning
 * and writing then happen in one transaction with the sales channel row locked.
 */
class AllowlistSynchronizer
{
    public function __construct(
        private readonly SalesChannelHosts $hosts,
        private readonly DnsResolverInterface $resolver,
        private readonly AllowlistGatewayInterface $gateway,
        private readonly ManagedIpStoreInterface $store,
        private readonly SyncPlanner $planner,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly CachedHostResolver|null $runtimeCache = null,
    ) {
    }

    /**
     * Configured sales channels plus those that still have managed IPs but were removed from the configuration.
     *
     * @return list<string>
     */
    public function getSalesChannelIds(): array
    {
        return \array_values(\array_unique([ ...$this->hosts->getSalesChannelIds(), ...$this->store->getSalesChannelIds() ]));
    }

    public function sync(string $salesChannelId, bool $dryRun = false): SalesChannelSyncResult
    {
        $salesChannelId = \strtolower($salesChannelId);
        $hostnames = $this->hosts->getHosts($salesChannelId);

        $results = [];
        foreach ($hostnames as $hostname) {
            $results[$hostname] = $this->resolver->resolve($hostname);
        }

        $this->warmRuntimeCache($results);

        return $this->apply($salesChannelId, $hostnames, $results, $dryRun);
    }

    /**
     * Removes the IPs the bundle added from the allowlist and forgets its state, e.g. before uninstalling.
     * Manually entered allowlist entries stay.
     *
     * @return list<string>
     */
    public function getCleanupSalesChannelIds(): array
    {
        return $this->store->getSalesChannelIds();
    }

    public function cleanup(string $salesChannelId, bool $dryRun = false): SalesChannelSyncResult
    {
        return $this->apply(\strtolower($salesChannelId), [], [], $dryRun);
    }

    /**
     * @param list<string>             $hostnames
     * @param array<string, DnsResult> $results
     */
    private function apply(string $salesChannelId, array $hostnames, array $results, bool $dryRun): SalesChannelSyncResult
    {
        try {
            $plan = $this->gateway->transactional(function () use ($salesChannelId, $hostnames, $results, $dryRun): SyncPlan {
                $plan = $this->planner->plan(
                    $salesChannelId,
                    $this->gateway->lockAndLoad($salesChannelId),
                    $this->store->load($salesChannelId),
                    $hostnames,
                    $results,
                );

                if (!$dryRun && $plan->hasChanges()) {
                    if ($plan->allowlistChanged) {
                        $this->gateway->save($salesChannelId, $plan->allowlist);
                    }

                    $this->store->apply($salesChannelId, $plan->upserts, $plan->deletes);
                }

                return $plan;
            });
        } catch (SalesChannelNotFoundException $exception) {
            if ($this->hosts->has($salesChannelId)) {
                return $this->failed($salesChannelId, $results, $exception);
            }

            // The sales channel was deleted, its managed IPs went with it
            if (!$dryRun) {
                $this->store->remove($salesChannelId);
            }

            $this->logger->info('Sales channel {salesChannelId} no longer exists, forgot its managed IPs.', [ 'salesChannelId' => $salesChannelId ]);

            return new SalesChannelSyncResult($salesChannelId, $results, null);
        } catch (\Throwable $exception) {
            return $this->failed($salesChannelId, $results, $exception);
        }

        $this->log($plan, $dryRun);

        return new SalesChannelSyncResult($salesChannelId, $results, $plan);
    }

    /**
     * @param array<string, DnsResult> $results
     */
    private function failed(string $salesChannelId, array $results, \Throwable $exception): SalesChannelSyncResult
    {
        $this->logger->error('Maintenance allowlist sync of sales channel {salesChannelId} failed: {message}', [
            'salesChannelId' => $salesChannelId,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);

        return new SalesChannelSyncResult($salesChannelId, $results, null, $exception->getMessage());
    }

    /**
     * @param array<string, DnsResult> $results
     */
    private function warmRuntimeCache(array $results): void
    {
        if (null === $this->runtimeCache) {
            return;
        }

        foreach ($results as $result) {
            try {
                $this->runtimeCache->warm($result);
            } catch (\Throwable $exception) {
                $this->logger->warning('Warming the runtime DNS cache failed: {message}', [ 'message' => $exception->getMessage() ]);
            }
        }
    }

    private function log(SyncPlan $plan, bool $dryRun): void
    {
        $context = [
            'salesChannelId' => $plan->salesChannelId,
            'added' => \implode(', ', $plan->added),
            'removed' => \implode(', ', $plan->removed),
            'dryRun' => $dryRun,
        ];

        foreach ($plan->warnings as $warning) {
            $this->logger->warning('Sales channel {salesChannelId}: ' . $warning, $context);
        }

        if ($plan->allowlistChanged) {
            $this->logger->notice(
                ($dryRun ? '[dry-run] ' : '') . 'Maintenance allowlist of sales channel {salesChannelId} changed, added: [{added}], removed: [{removed}].',
                $context,
            );

            return;
        }

        $this->logger->info('Maintenance allowlist of sales channel {salesChannelId} is up to date.', $context);
    }
}
