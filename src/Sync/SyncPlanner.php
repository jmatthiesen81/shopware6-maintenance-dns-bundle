<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

use Devable\MaintenanceDnsBundle\Dns\DnsResult;
use Devable\MaintenanceDnsBundle\Ip\IpAddressFilter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Computes the changes for one sales channel, without side effects.
 *
 * Rules:
 * - Successful lookup: the managed IPs of the host become exactly the resolved IPs.
 * - Temporary failure (timeout, SERVFAIL, …): the previously managed IPs of the host are kept.
 * - NXDOMAIN / no address: kept as well, unless `removeOnNegativeAnswer` is enabled.
 * - With `keepMaxAge` > 0, kept IPs are dropped once their last successful lookup (`resolvedAt`) is that many seconds
 *   old. IPs without a timestamp (e.g. from before the option was set) start their age at the current run.
 * - Hosts no longer configured: their managed IPs are dropped.
 * - The bundle only removes allowlist entries it added itself. Entries that existed before (manual entries)
 *   are never removed, and all other entries (other IPs, CIDR ranges) are kept unchanged and in order.
 */
final class SyncPlanner
{
    public function __construct(
        private readonly bool $removeOnNegativeAnswer = false,
        private readonly int $keepMaxAge = 0,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param list<string>|null        $allowlist current Shopware allowlist
     * @param array<string, ManagedIp> $managed   current managed IPs, keyed by IP
     * @param list<string>             $hostnames configured hostnames
     * @param array<string, DnsResult> $results   lookup results, keyed by hostname
     */
    public function plan(string $salesChannelId, array|null $allowlist, array $managed, array $hostnames, array $results): SyncPlan
    {
        $warnings = [];
        $now = $this->clock->now()->getTimestamp();
        $desired = $this->desiredHostnamesByIp($managed, $hostnames, $results, $now, $warnings);

        $resolved = [];
        foreach ($hostnames as $hostname) {
            if (null !== ($results[$hostname] ?? null) && $results[$hostname]->isSuccess()) {
                foreach ($results[$hostname]->ips as $ip) {
                    $resolved[$ip] = true;
                }
            }
        }

        $entries = $allowlist ?? [];
        $present = [];
        foreach ($entries as $entry) {
            $present[IpAddressFilter::normalize((string) $entry) ?? (string) $entry] = true;
        }

        $added = [];
        $removed = [];
        $upserts = [];
        $deletes = [];

        foreach ($managed as $ip => $record) {
            if (isset($desired[$ip])) {
                continue;
            }

            $deletes[] = $record->ip;

            if ($record->inAllowlist && isset($present[$record->ip])) {
                $removed[] = $record->ip;
                unset($present[$record->ip]);
            }
        }

        foreach ($desired as $ip => $ipHostnames) {
            $current = $managed[$ip] ?? null;
            $isPresent = isset($present[$ip]);

            if (null === $current) {
                // An IP that is already in the allowlist was entered manually and stays owned by the admin
                $inAllowlist = !$isPresent;
            } else {
                $inAllowlist = $current->inAllowlist || !$isPresent;

                if ($current->inAllowlist && !$isPresent) {
                    $warnings[] = \sprintf('Managed IP %s was removed from the allowlist by someone else and is added again.', $ip);
                }
            }

            if (!$isPresent) {
                $added[] = $ip;
                $present[$ip] = true;
            }

            $record = new ManagedIp($ip, $ipHostnames, $inAllowlist, $this->resolvedAt($current, isset($resolved[$ip]), $now));
            if (null === $current || !$current->equals($record)) {
                $upserts[$ip] = $record;
            }
        }

        $newEntries = \array_values(\array_filter(
            $entries,
            static fn (string $entry): bool => !\in_array(IpAddressFilter::normalize($entry) ?? $entry, $removed, true),
        ));
        \array_push($newEntries, ...$added);

        $allowlistChanged = [] !== $added || [] !== $removed;

        return new SyncPlan(
            $salesChannelId,
            $allowlistChanged ? ([] === $newEntries ? null : $newEntries) : $allowlist,
            $allowlistChanged,
            $added,
            $removed,
            $upserts,
            $deletes,
            $warnings,
        );
    }

    /**
     * @param array<string, ManagedIp> $managed
     * @param list<string>             $hostnames
     * @param array<string, DnsResult> $results
     * @param list<string>             $warnings
     *
     * @return array<string, list<string>> IP => hostnames, sorted
     */
    private function desiredHostnamesByIp(array $managed, array $hostnames, array $results, int $now, array &$warnings): array
    {
        $desired = [];

        foreach ($hostnames as $hostname) {
            $result = $results[$hostname] ?? null;

            if (null !== $result && $result->isSuccess()) {
                foreach ($result->ips as $ip) {
                    $desired[$ip][] = $hostname;
                }

                continue;
            }

            if (null !== $result && $result->status->isNegativeAnswer() && $this->removeOnNegativeAnswer) {
                $warnings[] = \sprintf('%s: %s, its managed IPs are removed.', $hostname, $result->error ?? $result->status->value);

                continue;
            }

            $kept = [];
            $expired = [];
            foreach ($managed as $ip => $record) {
                if (!\in_array($hostname, $record->hostnames, true)) {
                    continue;
                }

                if ($this->isExpired($record, $now)) {
                    $expired[] = $record->ip;

                    continue;
                }

                $desired[$ip][] = $hostname;
                $kept[] = $record->ip;
            }

            $actions = [];
            if ([] !== $kept) {
                $actions[] = 'keeping ' . \implode(', ', $kept);
            }
            if ([] !== $expired) {
                $actions[] = \sprintf('not keeping %s (last resolved more than %ds ago)', \implode(', ', $expired), $this->keepMaxAge);
            }

            $warnings[] = \sprintf(
                '%s: %s, %s.',
                $hostname,
                $result?->error ?? 'not resolved',
                [] === $actions ? 'nothing to keep' : \implode(', ', $actions),
            );
        }

        foreach ($desired as $ip => $ipHostnames) {
            $ipHostnames = \array_values(\array_unique($ipHostnames));
            \sort($ipHostnames);
            $desired[$ip] = $ipHostnames;
        }

        \ksort($desired, \SORT_STRING);

        /** @var array<string, list<string>> $desired */
        return $desired;
    }

    private function isExpired(ManagedIp $record, int $now): bool
    {
        return $this->keepMaxAge > 0 && null !== $record->resolvedAt && $record->resolvedAt + $this->keepMaxAge <= $now;
    }

    /**
     * Timestamp to store for a desired IP. Refreshed only after a tenth of `keepMaxAge`, so not every run rewrites
     * the state; an IP is therefore dropped at most 10 % early, never late.
     */
    private function resolvedAt(ManagedIp|null $current, bool $resolvedNow, int $now): int|null
    {
        $previous = $current?->resolvedAt;

        if (0 === $this->keepMaxAge) {
            return $previous;
        }

        if (!$resolvedNow) {
            return $previous ?? $now;
        }

        return null === $previous || $previous + \max(1, \intdiv($this->keepMaxAge, 10)) <= $now ? $now : $previous;
    }
}
