<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Determines the sales channel of a request and whether it is in maintenance mode, i.e. the sales channel whose
 * maintenance check the core is currently running.
 *
 * - Store API: `SalesChannelAuthenticationListener` checks the sales channel of the `sw-access-key` header, so the
 *   sales channel is looked up by that header, like the core does. The request attributes must not be used here:
 *   for a Store API URL behind a Storefront domain (e.g. `/de/store-api/...`), `RequestTransformer` fills them with
 *   the sales channel of that domain, which can differ from the one of the access key.
 * - Storefront and HTTP cache: `RequestTransformer` puts both into the (main) request attributes.
 */
class MaintenanceStateResolver
{
    /**
     * Memoized per request and mode, because the same request is checked before routing (HTTP cache, no route
     * scope yet) and again by the Store API listener (route scope known).
     *
     * @var \WeakMap<Request, array<string, MaintenanceState|null>>
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
        $main = $this->requestStack->getMainRequest() ?? $request;
        $storeApi = self::isStoreApiRequest($request) || self::isStoreApiRequest($main);
        $mode = $storeApi ? 'store-api' : 'default';

        $resolved = $this->resolved[$request] ?? [];
        if (!\array_key_exists($mode, $resolved)) {
            $resolved[$mode] = $storeApi ? $this->resolveByAccessKey($request, $main) : $this->resolveByAttributes($request, $main);
            $this->resolved[$request] = $resolved;
        }

        return $resolved[$mode];
    }

    private static function isStoreApiRequest(Request $request): bool
    {
        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE);

        return \is_array($scopes) && \in_array(StoreApiRouteScope::ID, $scopes, true);
    }

    /**
     * Never falls back to the request attributes, see the class comment.
     */
    private function resolveByAccessKey(Request $request, Request $main): MaintenanceState|null
    {
        $accessKey = $request->headers->get(PlatformRequest::HEADER_ACCESS_KEY) ?? $main->headers->get(PlatformRequest::HEADER_ACCESS_KEY);

        return \is_string($accessKey) && '' !== $accessKey ? $this->fetch('access_key = :value', $accessKey) : null;
    }

    private function resolveByAttributes(Request $request, Request $main): MaintenanceState|null
    {
        $salesChannelId = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID)
            ?? $main->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_ID);
        $salesChannelId = \is_string($salesChannelId) ? \strtolower($salesChannelId) : null;
        $maintenance = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE)
            ?? $main->attributes->get(SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE);

        if (null !== $salesChannelId && Uuid::isValid($salesChannelId) && null !== $maintenance) {
            return new MaintenanceState($salesChannelId, (bool) $maintenance);
        }

        // Store API request before routing (e.g. HTTP cache lookup of /store-api/...)
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
