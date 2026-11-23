<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Stores the managed IPs in the Shopware system configuration, one global entry per sales channel:
 * `DevableMaintenanceDnsWhitelist.managedIps.<salesChannelId>` => {"<ip>": {"hostnames": [...], "inAllowlist": bool, "resolvedAt": int}}
 * (`resolvedAt` only while `keep_max_age_seconds` is set)
 *
 * - No own table and no migration; the cleanup command removes all entries before the bundle is uninstalled.
 * - Not exposed publicly (unlike sales channel custom fields, which the Store API delivers).
 * - Written through SystemConfigService on the same database connection, so it is part of the sync transaction
 *   and the system config cache is invalidated.
 * - Read directly from the database, never from the (possibly stale) system config cache.
 */
class SystemConfigManagedIpStore implements ManagedIpStoreInterface
{
    public const KEY_PREFIX = 'DevableMaintenanceDnsWhitelist.managedIps.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function load(string $salesChannelId): array
    {
        $value = $this->connection->fetchOne(
            'SELECT configuration_value FROM system_config WHERE configuration_key = :key AND sales_channel_id IS NULL',
            [ 'key' => self::key($salesChannelId) ],
        );

        if (!\is_string($value)) {
            return [];
        }

        $decoded = \json_decode($value, true);
        $records = \is_array($decoded) && \is_array($decoded['_value'] ?? null) ? $decoded['_value'] : [];

        $managed = [];
        foreach ($records as $ip => $record) {
            if (!\is_array($record)) {
                continue;
            }

            $ip = (string) $ip;
            $hostnames = \is_array($record['hostnames'] ?? null) ? \array_values(\array_map('strval', $record['hostnames'])) : [];
            $resolvedAt = \is_int($record['resolvedAt'] ?? null) ? $record['resolvedAt'] : null;
            $managed[$ip] = new ManagedIp($ip, $hostnames, (bool) ($record['inAllowlist'] ?? false), $resolvedAt);
        }

        \ksort($managed, \SORT_STRING);

        return $managed;
    }

    public function getSalesChannelIds(): array
    {
        $keys = $this->connection->fetchFirstColumn(
            'SELECT configuration_key FROM system_config WHERE configuration_key LIKE :prefix AND sales_channel_id IS NULL',
            [ 'prefix' => \addcslashes(self::KEY_PREFIX, '%_') . '%' ],
        );

        return \array_values(\array_map(
            static fn (mixed $key): string => \substr((string) $key, \strlen(self::KEY_PREFIX)),
            $keys,
        ));
    }

    public function apply(string $salesChannelId, array $upserts, array $deletes): void
    {
        $managed = $this->load($salesChannelId);

        foreach ($deletes as $ip) {
            unset($managed[$ip]);
        }

        foreach ($upserts as $ip => $record) {
            $managed[$ip] = $record;
        }

        if ([] === $managed) {
            $this->remove($salesChannelId);

            return;
        }

        \ksort($managed, \SORT_STRING);

        $value = [];
        foreach ($managed as $ip => $record) {
            $value[$ip] = [ 'hostnames' => $record->hostnames, 'inAllowlist' => $record->inAllowlist ];
            if (null !== $record->resolvedAt) {
                $value[$ip]['resolvedAt'] = $record->resolvedAt;
            }
        }

        $this->systemConfig->set(self::key($salesChannelId), $value);
    }

    public function remove(string $salesChannelId): void
    {
        $this->systemConfig->delete(self::key($salesChannelId));
    }

    public static function key(string $salesChannelId): string
    {
        return self::KEY_PREFIX . \strtolower($salesChannelId);
    }
}
