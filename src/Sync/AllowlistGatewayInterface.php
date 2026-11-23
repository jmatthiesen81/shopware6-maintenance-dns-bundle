<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

/**
 * Access to the maintenance IP allowlist of a sales channel.
 */
interface AllowlistGatewayInterface
{
    /**
     * Runs the callback in one database transaction; also used for the managed IP store.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function transactional(\Closure $callback): mixed;

    /**
     * Locks the sales channel row until the end of the transaction and returns its current allowlist.
     *
     * @throws SalesChannelNotFoundException
     *
     * @return list<string>|null
     */
    public function lockAndLoad(string $salesChannelId): array|null;

    /**
     * @param list<string>|null $allowlist
     */
    public function save(string $salesChannelId, array|null $allowlist): void;
}
