<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

use Shopware\Core\PlatformRequest;
use Shopware\Core\SalesChannelRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Shows a badge on Storefront pages while the sales channel is in maintenance mode, so allowed clients (static
 * allowlist or runtime mode) see that the shop is not public right now.
 *
 * - Only Storefront HTML pages of the main request: no XHR, no ESI sub request, no redirect, no maintenance page.
 *   A client that is not allowed never gets such a page during maintenance, it is redirected to the maintenance page.
 * - The badge is inserted before `</body>`, theme independent and without asset compilation. It uses the CSP nonce of
 *   the request if Shopware set one.
 * - Responses with the badge are marked `private, no-store`, so neither the HTTP cache nor a reverse proxy keeps them.
 *   The core does not cache responses for allowed clients during maintenance anyway.
 */
class MaintenanceBadgeSubscriber implements EventSubscriberInterface
{
    public const POSITIONS = [ 'top-right', 'top-left', 'bottom-right', 'bottom-left' ];

    private const STOREFRONT_SCOPE = 'storefront';

    private const EXCLUDED_ROUTES = [ 'frontend.maintenance.page', 'frontend.maintenance.singlepage' ];

    public function __construct(
        private readonly string $text = 'Maintenance mode',
        private readonly string $position = 'top-right',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Late, after the core applied its cache headers
            KernelEvents::RESPONSE => [ 'onResponse', -1024 ],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->isApplicable($request, $response)) {
            return;
        }

        $content = (string) $response->getContent();
        $position = \strripos($content, '</body>');
        if (false === $position) {
            return;
        }

        $response->setContent(\substr($content, 0, $position) . $this->render($request) . \substr($content, $position));
        $response->headers->remove('Content-Length');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
    }

    private function isApplicable(Request $request, Response $response): bool
    {
        if (true !== (bool) $request->attributes->get(SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE)) {
            return false;
        }

        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE);
        if (!\is_array($scopes) || !\in_array(self::STOREFRONT_SCOPE, $scopes, true)) {
            return false;
        }

        if (\in_array($request->attributes->get('_route'), self::EXCLUDED_ROUTES, true) || $request->isXmlHttpRequest()) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->isRedirection()) {
            return false;
        }

        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return '' === $contentType || \str_starts_with(\strtolower($contentType), 'text/html');
    }

    private function render(Request $request): string
    {
        [ $vertical, $horizontal ] = \explode('-', \in_array($this->position, self::POSITIONS, true) ? $this->position : 'top-right');

        $nonce = $request->attributes->get(PlatformRequest::ATTRIBUTE_CSP_NONCE);
        $nonceAttribute = \is_string($nonce) && '' !== $nonce ? ' nonce="' . \htmlspecialchars($nonce, \ENT_QUOTES) . '"' : '';

        return \sprintf(
            '<style%s>.devable-maintenance-badge{position:fixed;%s:12px;%s:12px;z-index:2147483647;padding:4px 10px;'
            . 'border-radius:4px;background:#c62828;color:#fff;font:600 12px/1.5 system-ui,-apple-system,sans-serif;'
            . 'letter-spacing:.02em;box-shadow:0 1px 4px rgba(0,0,0,.35);opacity:.92;pointer-events:none}</style>'
            . '<div class="devable-maintenance-badge" role="status">%s</div>',
            $nonceAttribute,
            $vertical,
            $horizontal,
            \htmlspecialchars($this->text, \ENT_QUOTES | \ENT_SUBSTITUTE),
        );
    }
}
