<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Runtime;

use Devable\MaintenanceDnsBundle\Runtime\CachedHostResolver;
use Devable\MaintenanceDnsBundle\Runtime\MaintenanceRequestSubscriber;
use Devable\MaintenanceDnsBundle\Runtime\MaintenanceStateResolver;
use Devable\MaintenanceDnsBundle\Runtime\RuntimeAllowlist;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Devable\MaintenanceDnsBundle\Tests\StubDnsResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Routing\MaintenanceModeResolver as CoreMaintenanceModeResolver;
use Shopware\Storefront\Framework\Routing\MaintenanceModeResolver as StorefrontMaintenanceModeResolver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs the real maintenance decision classes of Shopware with the subscriber registered on a real event dispatcher.
 * This proves that the listener takes effect before the decision (acceptance criteria 6–8).
 */
class ShopwareMaintenanceIntegrationTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0190a0b0c0d0e0f00000000000000001';

    private RequestStack $requestStack;

    private CoreMaintenanceModeResolver $coreResolver;

    private StorefrontMaintenanceModeResolver $storefrontResolver;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();

        $dns = (new StubDnsResolver())->succeed('office.example.net', [ '81.2.69.10' ]);
        $hosts = new SalesChannelHosts([ self::SALES_CHANNEL_ID => [ 'office.example.net' ] ]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MaintenanceRequestSubscriber(
            new MaintenanceStateResolver($this->createStub(Connection::class), $this->requestStack),
            new RuntimeAllowlist($hosts, new CachedHostResolver($dns, new ArrayAdapter())),
        ));

        $this->coreResolver = new CoreMaintenanceModeResolver($dispatcher);
        $this->storefrontResolver = new StorefrontMaintenanceModeResolver($this->requestStack, $this->coreResolver);
    }

    public function testDynamicIpGetsSameAccessAsStaticAllowlistEntry(): void
    {
        $static = $this->storefrontRequest('85.10.20.1', true, [ '85.10.20.1' ]);
        $dynamic = $this->storefrontRequest('81.2.69.10', true, [ '85.10.20.1' ]);

        foreach ([ $static, $dynamic ] as $request) {
            $this->requestStack->push($request);

            static::assertFalse($this->storefrontResolver->shouldRedirect($request));
            static::assertTrue($this->storefrontResolver->shouldRedirectToShop($request));
            static::assertFalse($this->storefrontResolver->isMaintenanceRequest($request));
            static::assertFalse($this->coreResolver->isMaintenanceRequest($request));

            $this->requestStack->pop();
        }
    }

    public function testOtherIpIsRedirectedToMaintenancePage(): void
    {
        $request = $this->storefrontRequest('81.2.69.99', true, [ '85.10.20.1' ]);
        $this->requestStack->push($request);

        static::assertTrue($this->storefrontResolver->shouldRedirect($request));
        static::assertTrue($this->coreResolver->isMaintenanceRequest($request));
    }

    public function testWithoutMaintenanceNothingChanges(): void
    {
        $request = $this->storefrontRequest('81.2.69.10', false, []);
        $this->requestStack->push($request);

        static::assertFalse($this->storefrontResolver->shouldRedirect($request));
        static::assertFalse($this->coreResolver->isMaintenanceRequest($request));
        static::assertTrue($this->coreResolver->shouldBeCached($request));
    }

    public function testHttpCacheDecision(): void
    {
        // CacheStore does not cache responses for allowed clients during maintenance
        $request = $this->storefrontRequest('81.2.69.10', true, []);
        $this->requestStack->push($request);

        static::assertFalse($this->coreResolver->shouldBeCached($request));
    }

    public function testStoreApiDecisionWithAccessKey(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([ 'id' => self::SALES_CHANNEL_ID, 'maintenance' => 1 ]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MaintenanceRequestSubscriber(
            new MaintenanceStateResolver($connection, $this->requestStack),
            new RuntimeAllowlist(
                new SalesChannelHosts([ self::SALES_CHANNEL_ID => [ 'office.example.net' ] ]),
                new CachedHostResolver((new StubDnsResolver())->succeed('office.example.net', [ '81.2.69.10' ]), new ArrayAdapter()),
            ),
        ));
        $resolver = new CoreMaintenanceModeResolver($dispatcher);

        // What SalesChannelAuthenticationListener::handleMaintenanceMode() calls
        $allowed = Request::create('/store-api/product', server: [ 'REMOTE_ADDR' => '81.2.69.10' ]);
        $allowed->headers->set('sw-access-key', 'SWSCKEY');
        $denied = Request::create('/store-api/product', server: [ 'REMOTE_ADDR' => '81.2.69.99' ]);
        $denied->headers->set('sw-access-key', 'SWSCKEY');

        static::assertTrue($resolver->isClientAllowed($allowed, []));
        static::assertFalse($resolver->isClientAllowed($denied, []));
    }

    /**
     * @param list<string> $allowlist
     */
    private function storefrontRequest(string $clientIp, bool $maintenance, array $allowlist): Request
    {
        // Attributes as set by Shopware\Storefront\Framework\Routing\RequestTransformer
        $request = Request::create('https://shop.example.net/', server: [ 'REMOTE_ADDR' => $clientIp ]);
        $request->attributes->set('_route', 'frontend.home.page');
        $request->attributes->set('sw-sales-channel-id', self::SALES_CHANNEL_ID);
        $request->attributes->set('_is_sales_channel', true);
        $request->attributes->set('sw-maintenance', $maintenance);
        $request->attributes->set('sw-maintenance-ip-whitelist', \json_encode($allowlist));
        $request->attributes->set('sw-maintenance-ip-allowlist', \json_encode($allowlist));

        return $request;
    }
}
