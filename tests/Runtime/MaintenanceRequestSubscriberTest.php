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
use Shopware\Core\Framework\Adapter\Kernel\HttpCacheKernel;
use Shopware\Core\Framework\Routing\Event\MaintenanceModeRequestEvent;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class MaintenanceRequestSubscriberTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0190a0b0c0d0e0f00000000000000001';
    private const OTHER_SALES_CHANNEL_ID = '0190a0b0c0d0e0f00000000000000002';

    private StubDnsResolver $dns;

    protected function setUp(): void
    {
        $this->dns = (new StubDnsResolver())
            ->succeed('office.example.net', [ '81.2.69.10' ])
            ->succeed('agency.example.org', [ '85.10.20.1', '2a00:1450:4001:816::200e' ]);
    }

    public function testAllowsResolvedIpDuringMaintenance(): void
    {
        $event = $this->dispatch($this->request('85.10.20.1', true));

        static::assertTrue($event->isClientAllowed());
    }

    public function testAllowsIpv6InAnyNotation(): void
    {
        $event = $this->dispatch($this->request('2A00:1450:4001:0816:0:0:0:200E', true));

        static::assertTrue($event->isClientAllowed());
    }

    public function testStopsResolvingAtFirstMatch(): void
    {
        $this->dispatch($this->request('81.2.69.10', true));

        static::assertSame([ 'office.example.net' => 1 ], $this->dns->calls);
    }

    public function testOtherIpIsNotAllowed(): void
    {
        $event = $this->dispatch($this->request('81.2.69.99', true));

        static::assertFalse($event->isClientAllowed());
    }

    public function testNoEffectWithoutMaintenance(): void
    {
        $event = $this->dispatch($this->request('81.2.69.10', false));

        static::assertFalse($event->isClientAllowed());
        static::assertSame(0, $this->dns->totalCalls());
    }

    public function testAlreadyAllowedClientIsLeftAloneWithoutLookup(): void
    {
        $event = $this->dispatch($this->request('1.1.1.1', true), true);

        static::assertTrue($event->isClientAllowed());
        static::assertSame(0, $this->dns->totalCalls());
    }

    public function testHostnamesOfOtherSalesChannelsDoNotApply(): void
    {
        $event = $this->dispatch($this->request('81.2.69.10', true, self::OTHER_SALES_CHANNEL_ID));

        static::assertFalse($event->isClientAllowed());
    }

    public function testDnsFailureAllowsNothing(): void
    {
        $this->dns->fail('office.example.net')->fail('agency.example.org');

        $event = $this->dispatch($this->request('81.2.69.10', true));

        static::assertFalse($event->isClientAllowed());
    }

    public function testExceptionAllowsNothing(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('database gone'));

        $request = Request::create('/store-api/product', server: [ 'REMOTE_ADDR' => '81.2.69.10' ]);
        $request->headers->set('sw-access-key', 'KEY');

        $event = new MaintenanceModeRequestEvent($request, [], false);
        $this->subscriber($connection)->onMaintenanceModeRequest($event);

        static::assertFalse($event->isClientAllowed());
    }

    public function testUntrustedForwardedHeaderIsIgnored(): void
    {
        $request = $this->request('192.0.2.200', true);
        $request->headers->set('X-Forwarded-For', '81.2.69.10');

        static::assertFalse($this->dispatch($request)->isClientAllowed());
    }

    public function testForwardedHeaderOfTrustedProxyIsUsed(): void
    {
        Request::setTrustedProxies([ '192.0.2.200' ], Request::HEADER_X_FORWARDED_FOR);

        try {
            $request = $this->request('192.0.2.200', true);
            $request->headers->set('X-Forwarded-For', '81.2.69.10');

            static::assertTrue($this->dispatch($request)->isClientAllowed());

            // A spoofed entry left of the real client does not help
            $request = $this->request('192.0.2.200', true);
            $request->headers->set('X-Forwarded-For', '81.2.69.10, 81.2.69.99');

            static::assertFalse($this->dispatch($request)->isClientAllowed());
        } finally {
            Request::setTrustedProxies([], -1);
        }
    }

    public function testRunsEarlySoOthersCanStillDisallow(): void
    {
        $events = MaintenanceRequestSubscriber::getSubscribedEvents();

        static::assertSame([ 'onMaintenanceModeRequest', 100 ], $events[MaintenanceModeRequestEvent::class]);
        static::assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    public function testUsesHeaderNameOfTheCore(): void
    {
        static::assertSame(HttpCacheKernel::MAINTENANCE_WHITELIST_HEADER, MaintenanceRequestSubscriber::ALLOWLIST_HEADER);
    }

    public function testExtendsExistingAllowlistHeader(): void
    {
        $response = new Response();
        $response->headers->set('sw-maintenance-whitelist', '1.1.1.1');

        $this->subscriber()->onResponse($this->responseEvent($this->request('192.0.2.1', true), $response));

        static::assertSame('1.1.1.1,81.2.69.10,85.10.20.1,2a00:1450:4001:816::200e', $response->headers->get('sw-maintenance-whitelist'));
    }

    public function testDoesNotAddAllowlistHeader(): void
    {
        $response = new Response();

        $this->subscriber()->onResponse($this->responseEvent($this->request('192.0.2.1', true), $response));

        static::assertFalse($response->headers->has('sw-maintenance-whitelist'));
        static::assertSame(0, $this->dns->totalCalls());
    }

    public function testDoesNotExtendHeaderWithoutMaintenanceOrWhenDisabled(): void
    {
        $response = new Response();
        $response->headers->set('sw-maintenance-whitelist', '1.1.1.1');

        $this->subscriber()->onResponse($this->responseEvent($this->request('192.0.2.1', false), $response));
        $this->subscriber(extendHeader: false)->onResponse($this->responseEvent($this->request('192.0.2.1', true), $response));

        static::assertSame('1.1.1.1', $response->headers->get('sw-maintenance-whitelist'));
    }

    private function dispatch(Request $request, bool $alreadyAllowed = false): MaintenanceModeRequestEvent
    {
        $event = new MaintenanceModeRequestEvent($request, [ '1.1.1.1' ], $alreadyAllowed);
        $this->subscriber()->onMaintenanceModeRequest($event);

        return $event;
    }

    private function subscriber(Connection|null $connection = null, bool $extendHeader = true): MaintenanceRequestSubscriber
    {
        $hosts = new SalesChannelHosts([
            self::SALES_CHANNEL_ID => [ 'office.example.net', 'agency.example.org' ],
        ]);

        return new MaintenanceRequestSubscriber(
            new MaintenanceStateResolver($connection ?? $this->createStub(Connection::class), new RequestStack()),
            new RuntimeAllowlist($hosts, new CachedHostResolver($this->dns, new ArrayAdapter())),
            $extendHeader,
        );
    }

    private function request(string $remoteAddress, bool $maintenance, string $salesChannelId = self::SALES_CHANNEL_ID): Request
    {
        $request = Request::create('https://shop.example.net/', server: [ 'REMOTE_ADDR' => $remoteAddress ]);
        $request->attributes->set('sw-sales-channel-id', $salesChannelId);
        $request->attributes->set('sw-maintenance', $maintenance);

        return $request;
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
