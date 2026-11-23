<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Ip;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Accepts only single, valid IP addresses and returns them normalized and unique.
 * CIDR ranges, hostnames or other strings are never passed through.
 */
final class IpAddressFilter
{
    /**
     * Ranges that FILTER_FLAG_GLOBAL_RANGE does not reject on all PHP versions.
     */
    private const NON_PUBLIC_RANGES = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    public function __construct(
        private readonly bool $allowNonPublicIps = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Canonical form of a single IP address ("2001:DB8:0::1" → "2001:db8::1"), null for anything else.
     */
    public static function normalize(string $value): string|null
    {
        $value = \trim($value);
        if (false === \filter_var($value, \FILTER_VALIDATE_IP)) {
            return null;
        }

        $packed = \inet_pton($value);
        if (false === $packed) {
            return null;
        }

        $normalized = \inet_ntop($packed);

        return false === $normalized ? null : $normalized;
    }

    public function isAcceptable(string $ip): bool
    {
        if ($this->allowNonPublicIps) {
            return true;
        }

        return false !== \filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE)
            && !IpUtils::checkIp($ip, self::NON_PUBLIC_RANGES);
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    public function filter(array $addresses, string $hostname): array
    {
        $accepted = [];
        foreach ($addresses as $address) {
            $ip = self::normalize($address);

            if (null === $ip) {
                $this->logger->warning('Discarded invalid address in the DNS answer of {hostname}.', [ 'hostname' => $hostname ]);

                continue;
            }

            if (!$this->isAcceptable($ip)) {
                $this->logger->warning('Discarded non-public address {ip} in the DNS answer of {hostname}.', [ 'hostname' => $hostname, 'ip' => $ip ]);

                continue;
            }

            $accepted[$ip] = true;
        }

        return \array_keys($accepted);
    }
}
