<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

/**
 * Reads the system name servers, used when no name servers are configured.
 */
final class ResolvConf
{
    /**
     * @return list<string>
     */
    public static function nameservers(string $path = '/etc/resolv.conf'): array
    {
        if (!\is_readable($path)) {
            return [];
        }

        $content = \file_get_contents($path);
        if (false === $content) {
            return [];
        }

        return self::parse($content);
    }

    /**
     * @return list<string>
     */
    public static function parse(string $content): array
    {
        $nameservers = [];
        foreach (\preg_split('/\R/', $content) ?: [] as $line) {
            if (1 !== \preg_match('/^\s*nameserver\s+(\S+)/', $line, $match)) {
                continue;
            }

            // Link-local addresses with zone ID ("fe80::1%eth0") are not supported
            if (false !== \filter_var($match[1], \FILTER_VALIDATE_IP)) {
                $nameservers[] = $match[1];
            }
        }

        return \array_values(\array_unique($nameservers));
    }
}
