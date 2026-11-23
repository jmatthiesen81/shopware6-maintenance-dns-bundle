<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines the sales channel of a request and whether it is in maintenance mode.
 *
 * - Storefront: `RequestTransformer` puts both into the (main) request attributes.
 * - Store API: `SalesChannelAuthenticationListener` checks maintenance before it sets the sales channel ID,
 *   so the sales channel is looked up by the `sw-access-key` header, like the core does.
 */
class MaintenanceStateResolver
{
    /**
     * @var \WeakMap<Request, MaintenanceState|null>
     */
    private \WeakMap $resolved;

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
        $this->resolved = new \WeakMap();
    }

    public function resolve(Request $request): MaintenanceState|null
    {
        if (!$this->resolved->offsetExists($request)) {
            $this->resolved[$request] = $this->doResolve($request);
        }

        return $this->resolved[$request];
    }

    private function doResolve(Request $request): MaintenanceState|null
    {
        $main = $this->requestStack->getMainRequest() ?? $request;

        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID)
            ?? $main->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? \strtolower($salesChannelId) : null;
        $maintenance = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE)
            ?? $main->attributes->get(SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE);

        if (null !== $salesChannelId && Uuid::isValid($salesChannelId) && null !== $maintenance) {
            return new MaintenanceState($salesChannelId, (bool) $maintenance);
        }

        $accessKey = $request->headers->get(PlatformRequest::HEADER_ACCESS_KEY) ?? $main->headers->get(PlatformRequest::HEADER_ACCESS_KEY);
        if (\is_string($accessKey) && '' !== $accessKey) {
            return $this->fetch('access_key = :value', $accessKey);
        }

        if (null !== $salesChannelId && Uuid::isValid($salesChannelId)) {
            return $this->fetch('id = :value', Uuid::fromHexToBytes($salesChannelId));
        }

        return null;
    }

    private function fetch(string $condition, string $value): MaintenanceState|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, maintenance FROM sales_channel WHERE ' . $condition . ' AND active = 1',
            [ 'value' => $value ],
        );

        if (false === $row) {
            return null;
        }

        return new MaintenanceState((string) $row['id'], (bool) $row['maintenance']);
    }
}
