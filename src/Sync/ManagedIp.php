<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

/**
 * An IP the bundle manages for a sales channel.
 *
 * `inAllowlist` tells whether the bundle itself added the IP to the Shopware allowlist. It is false when the IP was
 * already entered manually; then the bundle never removes it from the allowlist.
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
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->ip === $other->ip
            && $this->inAllowlist === $other->inAllowlist
            && $this->hostnames === $other->hostnames;
    }
}
