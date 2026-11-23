<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Sync;

use Devable\MaintenanceDnsBundle\Sync\ManagedIp;
use Devable\MaintenanceDnsBundle\Sync\SystemConfigManagedIpStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SystemConfigManagedIpStoreTest extends TestCase
{
    private const SC = '0190a0b0c0d0e0f00000000000000001';
    private const KEY = 'DevableMaintenanceDnsWhitelist.managedIps.0190a0b0c0d0e0f00000000000000001';

    public function testLoadReadsFromDatabaseNotFromCache(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(static::stringContains('FROM system_config WHERE configuration_key = :key AND sales_channel_id IS NULL'), [ 'key' => self::KEY ])
            ->willReturn('{"_value":{"85.10.20.1":{"hostnames":["agency.example.org"],"inAllowlist":false},"81.2.69.10":{"hostnames":["office.example.net"],"inAllowlist":true},"bogus":"x"}}');

        $store = new SystemConfigManagedIpStore($connection, $this->createStub(SystemConfigService::class));

        static::assertEquals([
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'office.example.net' ], true),
            '85.10.20.1' => new ManagedIp('85.10.20.1', [ 'agency.example.org' ], false),
        ], $store->load(\strtoupper(self::SC)));
    }

    public function testLoadWithoutEntry(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        static::assertSame([], (new SystemConfigManagedIpStore($connection, $this->createStub(SystemConfigService::class)))->load(self::SC));
    }

    public function testApplyMergesAndWritesThroughSystemConfigService(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('{"_value":{"81.2.69.10":{"hostnames":["office.example.net"],"inAllowlist":true}}}');

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects($this->once())->method('set')->with(self::KEY, [
            '2a00:1450:4001:816::200e' => [ 'hostnames' => [ 'office.example.net' ], 'inAllowlist' => true ],
            '85.10.20.1' => [ 'hostnames' => [ 'agency.example.org' ], 'inAllowlist' => false, 'resolvedAt' => 1700000000 ],
        ]);

        (new SystemConfigManagedIpStore($connection, $systemConfig))->apply(self::SC, [
            '85.10.20.1' => new ManagedIp('85.10.20.1', [ 'agency.example.org' ], false, 1700000000),
            '2a00:1450:4001:816::200e' => new ManagedIp('2a00:1450:4001:816::200e', [ 'office.example.net' ], true),
        ], [ '81.2.69.10' ]);
    }

    public function testLoadReadsResolvedAt(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('{"_value":{"81.2.69.10":{"hostnames":["office.example.net"],"inAllowlist":true,"resolvedAt":1700000000},"81.2.69.20":{"hostnames":["office.example.net"],"inAllowlist":true,"resolvedAt":"bogus"}}}');

        $managed = (new SystemConfigManagedIpStore($connection, $this->createStub(SystemConfigService::class)))->load(self::SC);

        static::assertSame(1700000000, $managed['81.2.69.10']->resolvedAt);
        static::assertNull($managed['81.2.69.20']->resolvedAt);
    }

    public function testApplyDeletesEntryWhenEmpty(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('{"_value":{"81.2.69.10":{"hostnames":["office.example.net"],"inAllowlist":true}}}');

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->expects($this->never())->method('set');
        $systemConfig->expects($this->once())->method('delete')->with(self::KEY);

        (new SystemConfigManagedIpStore($connection, $systemConfig))->apply(self::SC, [], [ '81.2.69.10' ]);
    }

    public function testGetSalesChannelIds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchFirstColumn')
            ->with(static::stringContains('LIKE :prefix'), [ 'prefix' => 'DevableMaintenanceDnsWhitelist.managedIps.%' ])
            ->willReturn([ self::KEY ]);

        static::assertSame([ self::SC ], (new SystemConfigManagedIpStore($connection, $this->createStub(SystemConfigService::class)))->getSalesChannelIds());
    }
}
