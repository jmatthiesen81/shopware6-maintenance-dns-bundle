<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Integration;

use Devable\MaintenanceDnsBundle\Runtime\MaintenanceStateResolver;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Devable\MaintenanceDnsBundle\Sync\AllowlistGatewayInterface;
use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\ManagedIp;
use Devable\MaintenanceDnsBundle\Sync\SalesChannelNotFoundException;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use Devable\MaintenanceDnsBundle\Sync\SystemConfigManagedIpStore;
use Devable\MaintenanceDnsBundle\Tests\StubDnsResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs the managed IP store (system_config), the sync transaction and the Store API lookup against MySQL/MariaDB.
 *
 * Set DATABASE_URL to an empty test database, e.g.
 *   DATABASE_URL=mysql://root:root@127.0.0.1:3306/maintenance_dns_test composer test
 * The test creates reduced `sales_channel` and `system_config` tables with the columns used by the bundle.
 */
#[Group('integration')]
class DatabaseIntegrationTest extends TestCase
{
    private const SC1 = '0190a0b0c0d0e0f00000000000000001';
    private const SC2 = '0190a0b0c0d0e0f00000000000000002';

    private Connection $connection;

    protected function setUp(): void
    {
        $url = \getenv('DATABASE_URL');
        if (!\is_string($url) || '' === $url) {
            static::markTestSkipped('DATABASE_URL is not set');
        }

        $params = (new DsnParser([ 'mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql' ]))->parse($url);
        $this->connection = DriverManager::getConnection($params);

        $this->dropTables();
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE system_config (
                id BINARY(16) NOT NULL PRIMARY KEY,
                configuration_key VARCHAR(255) NOT NULL,
                configuration_value JSON NOT NULL,
                sales_channel_id BINARY(16) NULL,
                created_at DATETIME(3) NOT NULL,
                updated_at DATETIME(3) NULL
            ) ENGINE=InnoDB
            SQL);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE sales_channel (
                id BINARY(16) NOT NULL PRIMARY KEY,
                access_key VARCHAR(255) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                maintenance TINYINT(1) NOT NULL DEFAULT 0,
                maintenance_ip_whitelist JSON NULL
            ) ENGINE=InnoDB
            SQL);

        $this->insertSalesChannel(self::SC1, 'SWSCONE', true);
        $this->insertSalesChannel(self::SC2, 'SWSCTWO', false);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropTables();
            $this->connection->close();
        }
    }

    public function testStoreRoundTrip(): void
    {
        $store = $this->store();

        $store->apply(self::SC1, [
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'office.example.net' ], true),
            '2a00:1450:4001:816::200e' => new ManagedIp('2a00:1450:4001:816::200e', [ 'agency.example.org', 'office.example.net' ], false),
        ], []);

        static::assertEquals([
            '2a00:1450:4001:816::200e' => new ManagedIp('2a00:1450:4001:816::200e', [ 'agency.example.org', 'office.example.net' ], false),
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'office.example.net' ], true),
        ], $store->load(self::SC1));
        static::assertSame([ self::SC1 ], $store->getSalesChannelIds());
        static::assertSame([], $store->load(self::SC2));

        $store->apply(self::SC1, [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'qa.example.net' ], false) ], [ '2a00:1450:4001:816::200e' ]);
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'qa.example.net' ], false) ], $store->load(self::SC1));

        $store->apply(self::SC1, [], [ '81.2.69.10' ]);
        static::assertSame([], $store->getSalesChannelIds());
        static::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM system_config'));
    }

    public function testPrefixWildcardsAreEscaped(): void
    {
        $this->connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => 'DevableMaintenanceDnsWhitelistXmanagedIpsX' . self::SC1,
            'configuration_value' => '{"_value":{}}',
            'created_at' => '2026-11-23 00:00:00',
        ]);

        static::assertSame([], $this->store()->getSalesChannelIds());
    }

    public function testSynchronizationIsAtomicAndIdempotent(): void
    {
        $gateway = new DbalTestGateway($this->connection);
        $store = $this->store();
        $dns = (new StubDnsResolver())->succeed('office.example.net', [ '81.2.69.10' ]);
        $synchronizer = new AllowlistSynchronizer(new SalesChannelHosts([ self::SC1 => [ 'office.example.net' ] ]), $dns, $gateway, $store, new SyncPlanner());

        $this->connection->executeStatement('UPDATE sales_channel SET maintenance_ip_whitelist = ? WHERE id = ?', [ '["1.1.1.1"]', Uuid::fromHexToBytes(self::SC1) ]);

        $synchronizer->sync(self::SC1);
        static::assertSame([ '1.1.1.1', '81.2.69.10' ], $gateway->lockAndLoad(self::SC1));

        $synchronizer->sync(self::SC1);
        static::assertSame(1, $gateway->saves);

        // A failing write rolls back the managed IP records as well
        $dns->succeed('office.example.net', [ '81.2.69.20' ]);
        $gateway->failOnSave = true;
        $result = $synchronizer->sync(self::SC1);

        static::assertNotNull($result->error);
        static::assertSame([ '1.1.1.1', '81.2.69.10' ], $gateway->lockAndLoad(self::SC1));
        static::assertSame([ '81.2.69.10' ], \array_keys($store->load(self::SC1)));
    }

    public function testMaintenanceStateByAccessKey(): void
    {
        $resolver = new MaintenanceStateResolver($this->connection, new RequestStack());

        $request = Request::create('/store-api/product');
        $request->headers->set('sw-access-key', 'SWSCONE');
        $state = $resolver->resolve($request);
        static::assertNotNull($state);
        static::assertSame(self::SC1, $state->salesChannelId);
        static::assertTrue($state->active);

        $request = Request::create('/store-api/product');
        $request->headers->set('sw-access-key', 'SWSCTWO');
        static::assertFalse($resolver->resolve($request)?->active);

        $request = Request::create('/store-api/product');
        $request->headers->set('sw-access-key', 'UNKNOWN');
        static::assertNull($resolver->resolve($request));

        $request = Request::create('/');
        $request->attributes->set('sw-sales-channel-id', self::SC2);
        static::assertFalse($resolver->resolve($request)?->active);
    }

    private function store(): SystemConfigManagedIpStore
    {
        return new SystemConfigManagedIpStore($this->connection, new DbalSystemConfigService($this->connection));
    }

    private function dropTables(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS system_config');
        $this->connection->executeStatement('DROP TABLE IF EXISTS sales_channel');
    }

    private function insertSalesChannel(string $id, string $accessKey, bool $maintenance): void
    {
        $this->connection->insert('sales_channel', [
            'id' => Uuid::fromHexToBytes($id),
            'access_key' => $accessKey,
            'maintenance' => $maintenance ? 1 : 0,
        ]);
    }
}

/**
 * Gateway on the reduced test table; the real DalAllowlistGateway needs the Shopware kernel.
 *
 * @internal
 */
final class DbalTestGateway implements AllowlistGatewayInterface
{
    public int $saves = 0;

    public bool $failOnSave = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function transactional(\Closure $callback): mixed
    {
        return $this->connection->transactional(static fn () => $callback());
    }

    public function lockAndLoad(string $salesChannelId): array|null
    {
        $value = $this->connection->fetchOne('SELECT maintenance_ip_whitelist FROM sales_channel WHERE id = ? FOR UPDATE', [ Uuid::fromHexToBytes($salesChannelId) ]);
        if (false === $value) {
            throw SalesChannelNotFoundException::forId($salesChannelId);
        }

        /** @var list<string>|null $allowlist */
        $allowlist = null === $value ? null : \json_decode((string) $value, true);

        return $allowlist;
    }

    public function save(string $salesChannelId, array|null $allowlist): void
    {
        $this->connection->executeStatement(
            'UPDATE sales_channel SET maintenance_ip_whitelist = ? WHERE id = ?',
            [ null === $allowlist ? null : \json_encode($allowlist), Uuid::fromHexToBytes($salesChannelId) ],
        );

        if ($this->failOnSave) {
            throw new \RuntimeException('simulated failure after write');
        }

        ++$this->saves;
    }
}

/**
 * Writes global system config values like SystemConfigService::setMultiple() does, without its dependencies.
 *
 * @internal
 */
final class DbalSystemConfigService extends SystemConfigService
{
    /**
     * @phpstan-ignore constructor.missingParentCall
     */
    public function __construct(private readonly Connection $db)
    {
    }

    public function set(string $key, $value, ?string $salesChannelId = null /* , bool $silent = true */): void
    {
        $this->db->executeStatement('DELETE FROM system_config WHERE configuration_key = ? AND sales_channel_id IS NULL', [ $key ]);

        if (null !== $value) {
            $this->db->insert('system_config', [
                'id' => Uuid::randomBytes(),
                'configuration_key' => $key,
                'configuration_value' => \json_encode([ '_value' => $value ]),
                'created_at' => '2026-11-23 00:00:00',
            ]);
        }
    }

    public function delete(string $key, ?string $salesChannel = null /* , bool $silent = true */): void
    {
        $this->set($key, null);
    }
}
