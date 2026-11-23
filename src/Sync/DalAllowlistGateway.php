<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Reads and writes the allowlist through the DAL, so validation, write events and cache invalidation
 * (e.g. of the Storefront domain cache) work as for an admin change.
 */
class DalAllowlistGateway implements AllowlistGatewayInterface
{
    public const FIELD = 'maintenanceIpWhitelist';

    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly Connection $connection,
    ) {
    }

    public function transactional(\Closure $callback): mixed
    {
        return $this->connection->transactional(static fn () => $callback());
    }

    public function lockAndLoad(string $salesChannelId): array|null
    {
        // Serializes against parallel writes of the same sales channel (admin, other sync runs) until commit
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM sales_channel WHERE id = :id FOR UPDATE',
            [ 'id' => Uuid::fromHexToBytes($salesChannelId) ],
        );

        if (false === $exists) {
            throw SalesChannelNotFoundException::forId($salesChannelId);
        }

        $salesChannel = $this->salesChannelRepository->search(new Criteria([ $salesChannelId ]), Context::createDefaultContext())->first();
        if (null === $salesChannel) {
            throw SalesChannelNotFoundException::forId($salesChannelId);
        }

        $allowlist = $salesChannel->get(self::FIELD);

        return \is_array($allowlist) ? \array_values(\array_map('strval', $allowlist)) : null;
    }

    public function save(string $salesChannelId, array|null $allowlist): void
    {
        $this->salesChannelRepository->update([
            [ 'id' => $salesChannelId, self::FIELD => $allowlist ],
        ], Context::createDefaultContext());
    }
}
