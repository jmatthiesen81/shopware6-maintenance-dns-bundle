<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Runtime;

use Devable\MaintenanceDnsBundle\Runtime\MaintenanceStateResolver;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class MaintenanceStateResolverTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0190a0b0c0d0e0f00000000000000001';

    public function testStorefrontRequestUsesAttributesWithoutQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAssociative');

        $request = $this->storefrontRequest(true);
        $state = (new MaintenanceStateResolver($connection, new RequestStack()))->resolve($request);

        static::assertNotNull($state);
        static::assertSame(self::SALES_CHANNEL_ID, $state->salesChannelId);
        static::assertTrue($state->active);
    }

    public function testSubRequestUsesAttributesOfMainRequest(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAssociative');

        $stack = new RequestStack();
        $stack->push($this->storefrontRequest(false));
        $stack->push($subRequest = Request::create('/widgets/checkout/info'));

        $state = (new MaintenanceStateResolver($connection, $stack))->resolve($subRequest);

        static::assertNotNull($state);
        static::assertFalse($state->active);
    }

    public function testStoreApiRequestIsResolvedByAccessKey(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(static::stringContains('access_key = :value AND active = 1'), [ 'value' => 'SWSCACCESSKEY' ])
            ->willReturn([ 'id' => self::SALES_CHANNEL_ID, 'maintenance' => '1' ]);

        $request = Request::create('/store-api/product');
        $request->headers->set('sw-access-key', 'SWSCACCESSKEY');

        $resolver = new MaintenanceStateResolver($connection, new RequestStack());
        $state = $resolver->resolve($request);

        static::assertNotNull($state);
        static::assertSame(self::SALES_CHANNEL_ID, $state->salesChannelId);
        static::assertTrue($state->active);

        // Memoized per request
        static::assertSame($state, $resolver->resolve($request));
    }

    public function testUnknownAccessKey(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $request = Request::create('/store-api/product');
        $request->headers->set('sw-access-key', 'UNKNOWN');

        static::assertNull((new MaintenanceStateResolver($connection, new RequestStack()))->resolve($request));
    }

    public function testSalesChannelIdWithoutMaintenanceAttributeIsLookedUp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(static::stringContains('id = :value'), [ 'value' => Uuid::fromHexToBytes(self::SALES_CHANNEL_ID) ])
            ->willReturn([ 'id' => self::SALES_CHANNEL_ID, 'maintenance' => '0' ]);

        $request = Request::create('/');
        $request->attributes->set('sw-sales-channel-id', self::SALES_CHANNEL_ID);

        $state = (new MaintenanceStateResolver($connection, new RequestStack()))->resolve($request);

        static::assertNotNull($state);
        static::assertFalse($state->active);
    }

    public function testRequestWithoutSalesChannel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAssociative');

        static::assertNull((new MaintenanceStateResolver($connection, new RequestStack()))->resolve(Request::create('/api/_info/health-check')));
    }

    private function storefrontRequest(bool $maintenance): Request
    {
        $request = Request::create('https://shop.example.net/');
        $request->attributes->set('sw-sales-channel-id', \strtoupper(self::SALES_CHANNEL_ID));
        $request->attributes->set('sw-maintenance', $maintenance);

        return $request;
    }
}
