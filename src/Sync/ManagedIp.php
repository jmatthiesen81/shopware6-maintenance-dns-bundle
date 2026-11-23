<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

/**
 * An IP the bundle manages for a sales channel.
 *
 * `inAllowlist` tells whether the bundle itself added the IP to the Shopware allowlist. It is false when the IP was
 * already entered manually; then the bundle never removes it from the allowlist.
 *
 * `resolvedAt` is the Unix timestamp of the last successful lookup that returned the IP. It is only tracked while
 * `keep_max_age_seconds` is set, and limits how long the IP is kept while its hostnames cannot be resolved.
 */
final class ManagedIp
{
    /**
     * @param list<string> $hostnames hostnames that currently resolve (or last resolved) to this IP
     */
    public function __construct(
        public readonly string $ip,
        public readonly array $hostnames,
        public readonly bool $inAllowlist,
        public readonly int|null $resolvedAt = null,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->ip === $other->ip
            && $this->inAllowlist === $other->inAllowlist
            && $this->hostnames === $other->hostnames
            && $this->resolvedAt === $other->resolvedAt;
    }
}
