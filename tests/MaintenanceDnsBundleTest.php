<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests;

use Devable\MaintenanceDnsBundle\Command\CleanupCommand;
use Devable\MaintenanceDnsBundle\Command\SyncCommand;
use Devable\MaintenanceDnsBundle\Dns\DnsResolverInterface;
use Devable\MaintenanceDnsBundle\Dns\UdpDnsResolver;
use Devable\MaintenanceDnsBundle\MaintenanceDnsBundle;
use Devable\MaintenanceDnsBundle\Runtime\CachedHostResolver;
use Devable\MaintenanceDnsBundle\Runtime\MaintenanceRequestSubscriber;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use Devable\MaintenanceDnsBundle\Sync\SystemConfigManagedIpStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Psr\Log\NullLogger;

class MaintenanceDnsBundleTest extends TestCase
{
    private const SC1 = '018f0000000000000000000000000001';
    private const SC2 = '018f0000000000000000000000000002';

    public function testExtensionAlias(): void
    {
        static::assertSame('devable_maintenance_dns_whitelist', $this->getExtension()->getAlias());
    }

    public function testDisabledByDefaultRegistersOnlyCleanup(): void
    {
        $builder = $this->load([]);

        static::assertTrue($builder->getDefinition(CleanupCommand::class)->hasTag('console.command'));
        static::assertSame([], $builder->getDefinition(SalesChannelHosts::class)->getArgument(0));
        static::assertFalse($builder->hasDefinition(SyncCommand::class));
        static::assertFalse($builder->hasDefinition(MaintenanceRequestSubscriber::class));
        static::assertFalse($builder->hasDefinition(CachedHostResolver::class));
    }

    public function testDisabledIgnoresMissingSalesChannels(): void
    {
        $builder = $this->load([ 'enabled' => false, 'runtime' => [ 'enabled' => true ] ]);

        static::assertFalse($builder->hasDefinition(MaintenanceRequestSubscriber::class));
    }

    public function testDefaultsRegisterCommandOnly(): void
    {
        $builder = $this->load($this->config());

        static::assertTrue($builder->getDefinition(SyncCommand::class)->hasTag('console.command'));
        static::assertFalse($builder->hasDefinition(MaintenanceRequestSubscriber::class));
        static::assertFalse($builder->hasDefinition(CachedHostResolver::class));
        static::assertSame([ false, 300 ], \array_slice($builder->getDefinition(SyncCommand::class)->getArguments(), 2));
        static::assertNull($builder->getDefinition(AllowlistSynchronizer::class)->getArgument(6));
    }

    public function testRuntimeOnly(): void
    {
        $builder = $this->load($this->config([ 'command' => [ 'enabled' => false ], 'runtime' => [ 'enabled' => true ] ]));

        static::assertFalse($builder->hasDefinition(SyncCommand::class));
        static::assertTrue($builder->hasDefinition(CleanupCommand::class));
        static::assertTrue($builder->getDefinition(MaintenanceRequestSubscriber::class)->hasTag('kernel.event_subscriber'));

        $cache = $builder->getDefinition(CachedHostResolver::class);
        static::assertEquals(new Reference('cache.object'), $cache->getArgument(1));
        static::assertSame([ 300, 30, true, 3600 ], \array_slice($cache->getArguments(), 2, 4));
    }

    public function testBothModesWarmTheRuntimeCache(): void
    {
        $builder = $this->load($this->config([ 'runtime' => [ 'enabled' => true, 'cache_pool' => 'cache.redis' ] ]));

        static::assertEquals(new Reference(CachedHostResolver::class), $builder->getDefinition(AllowlistSynchronizer::class)->getArgument(6));
        static::assertEquals(new Reference('cache.redis'), $builder->getDefinition(CachedHostResolver::class)->getArgument(1));
    }

    public function testNormalizedConfiguration(): void
    {
        $builder = $this->load([
            'enabled' => true,
            'dns' => [ 'record_types' => [ 'a', ' aaaa ' ], 'timeout_seconds' => 1, 'nameservers' => [ '9.9.9.9', '2620:fe::fe' ] ],
            'command' => [ 'dry_run' => true, 'remove_on_negative_answer' => true ],
            'sales_channels' => [
                \strtoupper(self::SC1) => [ 'domains' => [ ' Office.Example.NET. ', 'office.example.net', 'agency.example.org' ] ],
                self::SC2 => [ 'domains' => [ 'qa.example.net' ] ],
            ],
        ]);

        static::assertSame([
            self::SC1 => [ 'office.example.net', 'agency.example.org' ],
            self::SC2 => [ 'qa.example.net' ],
        ], $builder->getDefinition(SalesChannelHosts::class)->getArgument(0));

        $resolver = $builder->getDefinition(UdpDnsResolver::class);
        static::assertSame([ [ 'A', 'AAAA' ], 1.0, 10, [ '9.9.9.9', '2620:fe::fe' ] ], \array_slice($resolver->getArguments(), 2, 4));
        static::assertSame([ true ], $builder->getDefinition(SyncPlanner::class)->getArguments());
        static::assertTrue($builder->getDefinition(SyncCommand::class)->getArgument(2));
    }

    public function testLoggingCanBeDisabled(): void
    {
        $builder = $this->load($this->config([ 'logging' => [ 'enabled' => false ] ]));

        $logger = $builder->getDefinition(UdpDnsResolver::class)->getArgument(6);
        static::assertInstanceOf(Reference::class, $logger);
        static::assertSame(NullLogger::class, $builder->getDefinition((string) $logger)->getClass());
    }

    public function testLoggerChannel(): void
    {
        $builder = $this->load($this->config([ 'logging' => [ 'channel' => 'dyndns' ] ]));

        static::assertSame([ [ 'channel' => 'dyndns' ] ], $builder->getDefinition(UdpDnsResolver::class)->getTag('monolog.logger'));
    }

    public function testStateIsStoredInSystemConfig(): void
    {
        $builder = $this->load($this->config());

        static::assertEquals(new Reference(SystemConfigManagedIpStore::class), $builder->getDefinition(AllowlistSynchronizer::class)->getArgument(3));
        static::assertEquals(new Reference(SystemConfigService::class), $builder->getDefinition(SystemConfigManagedIpStore::class)->getArgument(1));
    }

    public function testContainerCompilesWithBothModes(): void
    {
        $builder = $this->load($this->config([ 'runtime' => [ 'enabled' => true ] ]));
        $builder->register('logger', NullLogger::class);
        $builder->register(Connection::class, Connection::class)->setSynthetic(true);
        $builder->register('request_stack', RequestStack::class);
        $builder->register('cache.object', ArrayAdapter::class);
        $builder->register('sales_channel.repository', EntityRepository::class)->setSynthetic(true);
        $builder->register(SystemConfigService::class, SystemConfigService::class)->setSynthetic(true);
        $builder->register('lock.factory', LockFactory::class)->setArguments([ new Reference('lock.store') ]);
        $builder->register('lock.store', InMemoryStore::class);
        $builder->getDefinition(SyncCommand::class)->setPublic(true);
        $builder->getDefinition(CleanupCommand::class)->setPublic(true);
        $builder->getDefinition(MaintenanceRequestSubscriber::class)->setPublic(true);
        $builder->setAlias('test.resolver', DnsResolverInterface::class)->setPublic(true);
        $builder->compile();

        $builder->set(Connection::class, $this->createStub(Connection::class));
        $builder->set('sales_channel.repository', $this->createStub(EntityRepository::class));
        $builder->set(SystemConfigService::class, $this->createStub(SystemConfigService::class));

        static::assertInstanceOf(SyncCommand::class, $builder->get(SyncCommand::class));
        static::assertInstanceOf(CleanupCommand::class, $builder->get(CleanupCommand::class));
        static::assertInstanceOf(MaintenanceRequestSubscriber::class, $builder->get(MaintenanceRequestSubscriber::class));
        static::assertInstanceOf(UdpDnsResolver::class, $builder->get('test.resolver'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        $sc = [ self::SC1 => [ 'domains' => [ 'office.example.net' ] ] ];

        yield 'enabled without sales channels' => [ [ 'enabled' => true ], 'No sales channel configured' ];
        yield 'both modes disabled' => [ [ 'enabled' => true, 'command' => [ 'enabled' => false ], 'sales_channels' => $sc ], 'Neither "command.enabled" nor "runtime.enabled"' ];
        yield 'invalid sales channel id' => [ [ 'enabled' => true, 'sales_channels' => [ 'storefront' => [ 'domains' => [ 'office.example.net' ] ] ] ], 'not a valid sales channel ID' ];
        yield 'duplicate sales channel id' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ 'a.example.net' ] ], \strtoupper(self::SC1) => [ 'domains' => [ 'b.example.net' ] ] ] ], 'configured twice' ];
        yield 'missing domains' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [] ] ], 'domains' ];
        yield 'empty domains' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [] ] ] ], 'at least 1 element' ];
        yield 'empty hostname' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ '  ' ] ] ] ], 'must not be empty' ];
        yield 'ip as hostname' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ '81.2.69.10' ] ] ] ], 'is an IP address' ];
        yield 'cidr as hostname' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ '81.2.69.0/24' ] ] ] ], 'not a valid hostname' ];
        yield 'url as hostname' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ 'https://office.example.net' ] ] ] ], 'not a valid hostname' ];
        yield 'wildcard' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ '*.example.net' ] ] ] ], 'not a valid hostname' ];
        yield 'single label' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ 'localhost' ] ] ] ], 'not a valid hostname' ];
        yield 'hostname not a string' => [ [ 'enabled' => true, 'sales_channels' => [ self::SC1 => [ 'domains' => [ 42 ] ] ] ], 'must be a string' ];
        yield 'invalid record type' => [ [ 'enabled' => true, 'dns' => [ 'record_types' => [ 'MX' ] ], 'sales_channels' => $sc ], 'record_types' ];
        yield 'no record type' => [ [ 'enabled' => true, 'dns' => [ 'record_types' => [] ], 'sales_channels' => $sc ], 'record_types' ];
        yield 'timeout too high' => [ [ 'enabled' => true, 'dns' => [ 'timeout_seconds' => 30 ], 'sales_channels' => $sc ], 'timeout_seconds' ];
        yield 'cache ttl too low' => [ [ 'enabled' => true, 'dns' => [ 'cache_ttl_seconds' => 0 ], 'sales_channels' => $sc ], 'cache_ttl_seconds' ];
        yield 'invalid nameserver' => [ [ 'enabled' => true, 'dns' => [ 'nameservers' => [ 'dns.google' ] ], 'sales_channels' => $sc ], 'is not an IP address' ];
        yield 'unknown option' => [ [ 'enabled' => true, 'sales_channels' => $sc, 'domains' => [] ], 'Unrecognized option' ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigProvider')]
    public function testInvalidConfigIsRejected(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load($config);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizeHostnameProvider(): iterable
    {
        yield 'plain' => [ 'office.example.net', 'office.example.net' ];
        yield 'whitespace, case and trailing dot' => [ '  Office.Example.NET. ', 'office.example.net' ];
        yield 'dyndns' => [ 'my-home.dyndns.org', 'my-home.dyndns.org' ];
        yield 'punycode' => [ 'xn--bcher-kva.example', 'xn--bcher-kva.example' ];
    }

    #[DataProvider('normalizeHostnameProvider')]
    public function testNormalizeHostname(string $value, string $expected): void
    {
        static::assertSame($expected, MaintenanceDnsBundle::normalizeHostname($value));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function config(array $override = []): array
    {
        return \array_replace_recursive([
            'enabled' => true,
            'sales_channels' => [
                self::SC1 => [ 'domains' => [ 'office.example.net' ] ],
            ],
        ], $override);
    }

    private function getExtension(): ExtensionInterface
    {
        $extension = (new MaintenanceDnsBundle())->getContainerExtension();
        static::assertNotNull($extension);

        return $extension;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', \sys_get_temp_dir());
        $this->getExtension()->load([ $config ], $builder);

        return $builder;
    }
}
