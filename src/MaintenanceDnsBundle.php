<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle;

use Devable\MaintenanceDnsBundle\Command\CleanupCommand;
use Devable\MaintenanceDnsBundle\Command\SyncCommand;
use Devable\MaintenanceDnsBundle\Dns\DnsResolverInterface;
use Devable\MaintenanceDnsBundle\Dns\UdpDnsResolver;
use Devable\MaintenanceDnsBundle\Dns\UdpDnsTransport;
use Devable\MaintenanceDnsBundle\Ip\IpAddressFilter;
use Devable\MaintenanceDnsBundle\Runtime\CachedHostResolver;
use Devable\MaintenanceDnsBundle\Runtime\MaintenanceBadgeSubscriber;
use Devable\MaintenanceDnsBundle\Runtime\MaintenanceRequestSubscriber;
use Devable\MaintenanceDnsBundle\Runtime\MaintenanceStateResolver;
use Devable\MaintenanceDnsBundle\Runtime\RuntimeAllowlist;
use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\DalAllowlistGateway;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use Devable\MaintenanceDnsBundle\Sync\SystemConfigManagedIpStore;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ReferenceConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * @phpstan-type BundleConfig array{
 *     enabled: bool,
 *     dns: array{record_types: list<string>, timeout_seconds: float, max_records: int, cache_ttl_seconds: int, negative_cache_ttl_seconds: int, allow_non_public_ips: bool, nameservers: list<string>},
 *     command: array{enabled: bool, dry_run: bool, remove_on_negative_answer: bool, lock_ttl_seconds: int},
 *     runtime: array{enabled: bool, fail_closed: bool, stale_max_age_seconds: int, cache_pool: string, extend_cache_allowlist_header: bool},
 *     badge: array{enabled: bool, text: string, position: string},
 *     logging: array{enabled: bool, channel: string},
 *     sales_channels: array<string, array{domains: list<string>}>
 * }
 */
class MaintenanceDnsBundle extends AbstractBundle
{
    /**
     * Set explicitly, the alias derived from the class name would be "maintenance_dns".
     */
    protected string $extensionAlias = 'devable_maintenance_dns_whitelist';

    private const NULL_LOGGER = 'devable_maintenance_dns_whitelist.null_logger';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();

        $rootNode
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['enabled'] && [] === $config['sales_channels'])
                ->thenInvalid('No sales channel configured. Add at least one entry under "sales_channels" or set "enabled: false".')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => $config['enabled'] && !$config['command']['enabled'] && !$config['runtime']['enabled'])
                ->thenInvalid('Neither "command.enabled" nor "runtime.enabled" is set, the bundle would do nothing. Enable one of them or set "enabled: false".')
            ->end()
            ->children()
                ->booleanNode('enabled')
                    ->info('Global switch. While disabled, only the cleanup command ' . CleanupCommand::NAME . ' is registered.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('dns')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('record_types')
                            ->info('Record types to resolve: "A" (IPv4) and optionally "AAAA" (IPv6).')
                            ->defaultValue([ 'A' ])
                            ->requiresAtLeastOneElement()
                            ->enumPrototype()
                                ->values([ 'A', 'AAAA' ])
                                ->beforeNormalization()
                                    ->ifString()
                                    ->then(static fn (string $value): string => \strtoupper(\trim($value)))
                                ->end()
                            ->end()
                        ->end()
                        ->floatNode('timeout_seconds')
                            ->info('Total time budget for resolving one hostname (all record types and name servers).')
                            ->defaultValue(2.0)
                            ->min(0.1)
                            ->max(10.0)
                        ->end()
                        ->integerNode('max_records')
                            ->info('Maximum number of addresses used per hostname.')
                            ->defaultValue(10)
                            ->min(1)
                            ->max(50)
                        ->end()
                        ->integerNode('cache_ttl_seconds')
                            ->info('Runtime mode: how long a successful lookup is cached.')
                            ->defaultValue(300)
                            ->min(10)
                            ->max(86400)
                        ->end()
                        ->integerNode('negative_cache_ttl_seconds')
                            ->info('Runtime mode: how long a failed lookup is cached, so a broken name server does not slow down every request.')
                            ->defaultValue(30)
                            ->min(1)
                            ->max(3600)
                        ->end()
                        ->booleanNode('allow_non_public_ips')
                            ->info('Accept private, loopback, link-local, multicast and other not globally routable addresses.')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('nameservers')
                            ->info('Name server IPs to ask (port 53). Empty: the name servers of /etc/resolv.conf.')
                            ->defaultValue([])
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $value): bool => !\is_string($value) || false === \filter_var($value, \FILTER_VALIDATE_IP))
                                    ->thenInvalid('Name server %s is not an IP address.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('command')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the command ' . SyncCommand::NAME . ' (persistent synchronization).')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('dry_run')
                            ->info('Always run the command as dry run, nothing is written.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('remove_on_negative_answer')
                            ->info('Remove the managed IPs of a hostname on NXDOMAIN or an answer without addresses. Timeouts and server errors never remove anything.')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('lock_ttl_seconds')
                            ->info('Lifetime of the lock against parallel runs. Use a shared lock store (framework.lock) with several servers.')
                            ->defaultValue(300)
                            ->min(10)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('runtime')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Allow the IPs of the hostnames at runtime, during the maintenance check, without changing the sales channel.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('fail_closed')
                            ->info('On DNS failure allow nothing. If false, the last successful answer is used up to "stale_max_age_seconds".')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('stale_max_age_seconds')
                            ->info('Maximum age of the last successful answer used when "fail_closed" is false.')
                            ->defaultValue(3600)
                            ->min(0)
                            ->max(86400)
                        ->end()
                        ->scalarNode('cache_pool')
                            ->info('PSR-6 cache pool service. Must be shared between all servers (e.g. Redis) to avoid lookups per server.')
                            ->defaultValue('cache.object')
                            ->cannotBeEmpty()
                        ->end()
                        ->booleanNode('extend_cache_allowlist_header')
                            ->info('Add the runtime IPs to the maintenance allowlist header the core sends for the HTTP cache.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('badge')
                    ->info('Badge on Storefront pages while the sales channel is in maintenance mode, visible to allowed clients only.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('text')
                            ->defaultValue('Maintenance mode')
                            ->cannotBeEmpty()
                        ->end()
                        ->enumNode('position')
                            ->values(MaintenanceBadgeSubscriber::POSITIONS)
                            ->defaultValue('top-right')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('channel')
                            ->info('Monolog channel')
                            ->defaultValue('maintenance_dns')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sales_channels')
                    ->info('Sales channel ID => hostnames whose IPs are allowed during maintenance.')
                    ->useAttributeAsKey('id')
                    ->normalizeKeys(false)
                    ->defaultValue([])
                    ->validate()
                        ->always(static function (array $salesChannels): array {
                            $normalized = [];
                            foreach ($salesChannels as $id => $salesChannel) {
                                $id = \strtolower(\trim((string) $id));
                                if (!Uuid::isValid($id)) {
                                    throw new \InvalidArgumentException(\sprintf('"%s" is not a valid sales channel ID (32 hexadecimal characters).', $id));
                                }

                                if (isset($normalized[$id])) {
                                    throw new \InvalidArgumentException(\sprintf('Sales channel ID "%s" is configured twice.', $id));
                                }

                                $normalized[$id] = $salesChannel;
                            }

                            return $normalized;
                        })
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('domains')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()
                                    ->validate()
                                        ->always(static fn (mixed $value): string => self::normalizeHostname($value))
                                    ->end()
                                ->end()
                                ->validate()
                                    ->always(static fn (array $domains): array => \array_values(\array_unique($domains)))
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param BundleConfig $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        $logger = service('logger');
        if (!$config['logging']['enabled']) {
            $services->set(self::NULL_LOGGER, NullLogger::class);
            $logger = service(self::NULL_LOGGER);
        }
        $channel = $config['logging']['channel'];

        // Disabled: no hostnames, only the cleanup command is usable
        $hosts = [];
        foreach ($config['enabled'] ? $config['sales_channels'] : [] as $salesChannelId => $salesChannel) {
            $hosts[$salesChannelId] = $salesChannel['domains'];
        }
        $runtimeEnabled = $config['enabled'] && $config['runtime']['enabled'];

        $services
            ->set(SalesChannelHosts::class)
            ->args([ $hosts ])
        ;

        $services
            ->set(IpAddressFilter::class)
            ->args([ $config['dns']['allow_non_public_ips'], $logger ])
            ->tag('monolog.logger', [ 'channel' => $channel ])
        ;

        $services->set(UdpDnsTransport::class);

        $services
            ->set(UdpDnsResolver::class)
            ->args([
                service(UdpDnsTransport::class),
                service(IpAddressFilter::class),
                $config['dns']['record_types'],
                (float) $config['dns']['timeout_seconds'],
                $config['dns']['max_records'],
                $config['dns']['nameservers'],
                $logger,
            ])
            ->tag('monolog.logger', [ 'channel' => $channel ])
        ;

        $services->alias(DnsResolverInterface::class, UdpDnsResolver::class);

        $this->loadPersistence($config, $services, $logger, $channel, $runtimeEnabled);

        if ($runtimeEnabled) {
            $this->loadRuntime($config, $services, $logger, $channel);
        }

        if ($config['enabled'] && $config['badge']['enabled']) {
            $services
                ->set(MaintenanceBadgeSubscriber::class)
                ->args([ $config['badge']['text'], $config['badge']['position'] ])
                ->tag('kernel.event_subscriber')
            ;
        }

        if ($config['enabled'] && $config['command']['enabled']) {
            $services
                ->set(SyncCommand::class)
                ->args([
                    service(AllowlistSynchronizer::class),
                    service('lock.factory'),
                    $config['command']['dry_run'],
                    $config['command']['lock_ttl_seconds'],
                ])
                ->tag('console.command', [ 'command' => SyncCommand::NAME ])
            ;
        }
    }

    /**
     * Lowercases, trims and validates a configured hostname. Rejects IP addresses, URLs, wildcards and names
     * without a dot.
     */
    public static function normalizeHostname(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Hostname must be a string, got %s.', \get_debug_type($value)));
        }

        $hostname = \rtrim(\strtolower(\trim($value)), '.');

        if ('' === $hostname) {
            throw new \InvalidArgumentException('Hostname must not be empty.');
        }

        if (false !== \filter_var($hostname, \FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is an IP address, not a hostname. Add static IPs to the maintenance allowlist of the sales channel instead.', $value));
        }

        if (
            \strlen($hostname) > 253
            || !\str_contains($hostname, '.')
            || false === \filter_var($hostname, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)
        ) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid hostname (e.g. "office.example.net", IDNs in punycode).', $value));
        }

        return $hostname;
    }

    /**
     * @param BundleConfig $config
     */
    private function loadRuntime(array $config, ServicesConfigurator $services, ReferenceConfigurator $logger, string $channel): void
    {
        $services
            ->set(CachedHostResolver::class)
            ->args([
                service(DnsResolverInterface::class),
                service($config['runtime']['cache_pool']),
                $config['dns']['cache_ttl_seconds'],
                $config['dns']['negative_cache_ttl_seconds'],
                $config['runtime']['fail_closed'],
                $config['runtime']['stale_max_age_seconds'],
                $logger,
            ])
            ->tag('monolog.logger', [ 'channel' => $channel ])
        ;

        $services
            ->set(RuntimeAllowlist::class)
            ->args([ service(SalesChannelHosts::class), service(CachedHostResolver::class) ])
        ;

        $services
            ->set(MaintenanceStateResolver::class)
            ->args([ service(Connection::class), service('request_stack') ])
        ;

        $services
            ->set(MaintenanceRequestSubscriber::class)
            ->args([
                service(MaintenanceStateResolver::class),
                service(RuntimeAllowlist::class),
                $config['runtime']['extend_cache_allowlist_header'],
                $logger,
            ])
            ->tag('kernel.event_subscriber')
            ->tag('monolog.logger', [ 'channel' => $channel ])
        ;
    }

    /**
     * Registered also while the bundle is disabled, so the cleanup command can remove what the bundle added.
     *
     * @param BundleConfig $config
     */
    private function loadPersistence(array $config, ServicesConfigurator $services, ReferenceConfigurator $logger, string $channel, bool $runtimeEnabled): void
    {
        $services
            ->set(SyncPlanner::class)
            ->args([ $config['command']['remove_on_negative_answer'] ])
        ;

        $services
            ->set(SystemConfigManagedIpStore::class)
            ->args([ service(Connection::class), service(SystemConfigService::class) ])
        ;

        $services
            ->set(DalAllowlistGateway::class)
            ->args([ service('sales_channel.repository'), service(Connection::class) ])
        ;

        $services
            ->set(AllowlistSynchronizer::class)
            ->args([
                service(SalesChannelHosts::class),
                service(DnsResolverInterface::class),
                service(DalAllowlistGateway::class),
                service(SystemConfigManagedIpStore::class),
                service(SyncPlanner::class),
                $logger,
                // Cache warming for the runtime mode
                $runtimeEnabled ? service(CachedHostResolver::class) : null,
            ])
            ->tag('monolog.logger', [ 'channel' => $channel ])
        ;

        $services
            ->set(CleanupCommand::class)
            ->args([
                service(AllowlistSynchronizer::class),
                service('lock.factory'),
                $config['command']['lock_ttl_seconds'],
            ])
            ->tag('console.command', [ 'command' => CleanupCommand::NAME ])
        ;
    }
}
