<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Routing\Event\MaintenanceModeRequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runtime mode: allows clients whose IP matches a configured hostname of the sales channel while it is in
 * maintenance mode.
 *
 * Shopware dispatches MaintenanceModeRequestEvent in `MaintenanceModeResolver::isClientAllowed()`, which is the
 * single decision point used by the Storefront (redirect to the maintenance page), the Store API
 * (SalesChannelAuthenticationListener) and the HTTP cache (CacheStore). The listener only ever allows, it never
 * disallows, and it does nothing when the client is already allowed or the sales channel is not in maintenance mode.
 */
class MaintenanceRequestSubscriber implements EventSubscriberInterface
{
    /**
     * Header names of the core (sw-maintenance-allowlist since 6.7.13, sw-maintenance-whitelist before and as fallback).
     */
    public const ALLOWLIST_HEADERS = [ 'sw-maintenance-allowlist', 'sw-maintenance-whitelist' ];

    public function __construct(
        private readonly MaintenanceStateResolver $stateResolver,
        private readonly RuntimeAllowlist $allowlist,
        private readonly bool $extendCacheAllowlistHeader = true,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Early, so listeners with a lower priority can still disallow the client
            MaintenanceModeRequestEvent::class => [ 'onMaintenanceModeRequest', 100 ],
            KernelEvents::RESPONSE => [ 'onResponse', 0 ],
        ];
    }

    public function onMaintenanceModeRequest(MaintenanceModeRequestEvent $event): void
    {
        if ($event->isClientAllowed()) {
            return;
        }

        $request = $event->getRequest();
        $clientIp = $request->getClientIp();

        if (null === $clientIp) {
            return;
        }

        try {
            $state = $this->stateResolver->resolve($request);
            if (null === $state || !$state->active) {
                return;
            }

            $hostname = $this->allowlist->findMatchingHost($state->salesChannelId, $clientIp);
        } catch (\Throwable $exception) {
            // Fail closed: an error must never allow a client, nor break the request
            $this->logger->error('Runtime maintenance allowlist check failed: {message}', [ 'message' => $exception->getMessage(), 'exception' => $exception ]);

            return;
        }

        if (null === $hostname) {
            return;
        }

        $this->logger->info('Allowed {ip} during maintenance of sales channel {salesChannelId} via {hostname}.', [
            'ip' => $clientIp,
            'salesChannelId' => $state->salesChannelId,
            'hostname' => $hostname,
        ]);

        $event->allowClient();
    }

    /**
     * The HTTP cache (HttpCacheKernel) bypasses cached maintenance pages for IPs in the allowlist header of the
     * response. Adds the runtime IPs to that header, but only when the core already sent it.
     */
    public function onResponse(ResponseEvent $event): void
    {
        if (!$this->extendCacheAllowlistHeader || !$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $present = \array_values(\array_filter(self::ALLOWLIST_HEADERS, static fn (string $name): bool => $headers->has($name)));

        if ([] === $present) {
            return;
        }

        try {
            $state = $this->stateResolver->resolve($event->getRequest());
            if (null === $state || !$state->active) {
                return;
            }

            $runtimeIps = $this->allowlist->getIps($state->salesChannelId);
        } catch (\Throwable $exception) {
            $this->logger->error('Extending the maintenance allowlist header failed: {message}', [ 'message' => $exception->getMessage(), 'exception' => $exception ]);

            return;
        }

        if ([] === $runtimeIps) {
            return;
        }

        foreach ($present as $name) {
            $ips = \array_filter(\explode(',', (string) $headers->get($name)));
            $headers->set($name, \implode(',', \array_values(\array_unique([ ...$ips, ...$runtimeIps ]))));
        }
    }
}
