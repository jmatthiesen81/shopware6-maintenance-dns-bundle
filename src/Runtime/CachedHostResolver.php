<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

use Devable\MaintenanceDnsBundle\Dns\DnsResolverInterface;
use Devable\MaintenanceDnsBundle\Dns\DnsResult;
use Devable\MaintenanceDnsBundle\Dns\DnsStatus;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

/**
 * Runtime DNS lookups with a shared cache, so not every request causes a DNS query.
 *
 * - Successful answers are cached for `cacheTtl` seconds.
 * - Failures are cached for `negativeCacheTtl` seconds, so a broken name server does not slow down every request.
 * - Fail-closed (default): a failed lookup allows nothing. Otherwise the last successful answer is used, but only
 *   while it is younger than `staleMaxAge` seconds.
 *
 * @phpstan-type CacheEntry array{status: string, ips: list<string>, resolvedAt: int, lastGoodIps: list<string>, lastGoodAt: int|null}
 */
final class CachedHostResolver
{
    private const KEY_PREFIX = 'devable_maintenance_dns_';

    public function __construct(
        private readonly DnsResolverInterface $resolver,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $cacheTtl = 300,
        private readonly int $negativeCacheTtl = 30,
        private readonly bool $failClosed = true,
        private readonly int $staleMaxAge = 0,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @return list<string> IPs that may be allowed right now
     */
    public function getIps(string $hostname): array
    {
        $entry = $this->load($hostname);

        if (null === $entry || !$this->isFresh($entry)) {
            $entry = $this->store($this->resolver->resolve($hostname), $entry);
        }

        return $this->effectiveIps($hostname, $entry);
    }

    /**
     * Stores a fresh result, e.g. from the sync command (cache warming).
     */
    public function warm(DnsResult $result): void
    {
        $this->store($result, $this->load($result->hostname));
    }

    /**
     * @param CacheEntry|null $previous
     *
     * @return CacheEntry
     */
    private function store(DnsResult $result, array|null $previous): array
    {
        $now = $this->now();

        $entry = [
            'status' => $result->status->value,
            'ips' => $result->ips,
            'resolvedAt' => $now,
            'lastGoodIps' => $result->isSuccess() ? $result->ips : ($previous['lastGoodIps'] ?? []),
            'lastGoodAt' => $result->isSuccess() ? $now : ($previous['lastGoodAt'] ?? null),
        ];

        $item = $this->cache->getItem($this->key($result->hostname));
        $item->set($entry);
        $item->expiresAfter(\max($this->cacheTtl, $this->negativeCacheTtl, $this->failClosed ? 0 : $this->staleMaxAge) + 60);
        $this->cache->save($item);

        return $entry;
    }

    /**
     * @return CacheEntry|null
     */
    private function load(string $hostname): array|null
    {
        $item = $this->cache->getItem($this->key($hostname));
        if (!$item->isHit()) {
            return null;
        }

        $entry = $item->get();
        if (!\is_array($entry) || !isset($entry['status'], $entry['resolvedAt']) || null === DnsStatus::tryFrom((string) $entry['status'])) {
            return null;
        }

        /** @var CacheEntry $entry */
        return $entry;
    }

    /**
     * @param CacheEntry $entry
     */
    private function isFresh(array $entry): bool
    {
        $ttl = DnsStatus::Success->value === $entry['status'] ? $this->cacheTtl : $this->negativeCacheTtl;

        return $entry['resolvedAt'] + $ttl > $this->now();
    }

    /**
     * @param CacheEntry $entry
     *
     * @return list<string>
     */
    private function effectiveIps(string $hostname, array $entry): array
    {
        if (DnsStatus::Success->value === $entry['status']) {
            return $entry['ips'];
        }

        if ($this->failClosed || null === $entry['lastGoodAt'] || $entry['lastGoodAt'] + $this->staleMaxAge <= $this->now()) {
            return [];
        }

        $this->logger->info('Using the last successful DNS answer of {hostname} from {age}s ago.', [
            'hostname' => $hostname,
            'age' => $this->now() - $entry['lastGoodAt'],
        ]);

        return $entry['lastGoodIps'];
    }

    private function key(string $hostname): string
    {
        return self::KEY_PREFIX . \hash('xxh128', \strtolower($hostname));
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
