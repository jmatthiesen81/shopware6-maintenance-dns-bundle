<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Ip;

use Devable\MaintenanceDnsBundle\Ip\IpAddressFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class IpAddressFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'ipv4' => [ '203.0.113.10', '203.0.113.10' ];
        yield 'whitespace' => [ ' 203.0.113.10 ', '203.0.113.10' ];
        yield 'ipv6 compressed' => [ '2001:DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1' ];
        yield 'cidr' => [ '203.0.113.0/24', null ];
        yield 'leading zeros' => [ '203.000.113.010', null ];
        yield 'hostname' => [ 'office.example.net', null ];
        yield 'empty' => [ '', null ];
        yield 'list' => [ '203.0.113.10,198.51.100.1', null ];
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $value, string|null $expected): void
    {
        static::assertSame($expected, IpAddressFilter::normalize($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicProvider(): iterable
    {
        yield 'private 10/8' => [ '10.1.2.3' ];
        yield 'private 172.16/12' => [ '172.16.0.1' ];
        yield 'private 192.168/16' => [ '192.168.178.1' ];
        yield 'loopback' => [ '127.0.0.1' ];
        yield 'link-local' => [ '169.254.1.1' ];
        yield 'this network' => [ '0.0.0.0' ];
        yield 'carrier-grade nat' => [ '100.64.0.1' ];
        yield 'multicast' => [ '224.0.0.1' ];
        yield 'reserved' => [ '240.0.0.1' ];
        yield 'broadcast' => [ '255.255.255.255' ];
        yield 'ipv6 loopback' => [ '::1' ];
        yield 'ipv6 unspecified' => [ '::' ];
        yield 'ipv6 unique local' => [ 'fd00::1' ];
        yield 'ipv6 link-local' => [ 'fe80::1' ];
        yield 'ipv6 multicast' => [ 'ff02::1' ];
        yield 'ipv4-mapped' => [ '::ffff:127.0.0.1' ];
        yield 'ipv6 documentation' => [ '2001:db8::1' ];
    }

    #[DataProvider('nonPublicProvider')]
    public function testNonPublicAddressesAreRejectedByDefault(string $ip): void
    {
        static::assertFalse((new IpAddressFilter())->isAcceptable($ip));
        static::assertTrue((new IpAddressFilter(true))->isAcceptable($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicProvider(): iterable
    {
        yield 'ipv4' => [ '93.184.216.34' ];
        yield 'ipv6' => [ '2a00:1450:4001:816::200e' ];
    }

    #[DataProvider('publicProvider')]
    public function testPublicAddressesAreAccepted(string $ip): void
    {
        static::assertTrue((new IpAddressFilter())->isAcceptable($ip));
    }

    public function testFilterNormalizesDeduplicatesAndLogs(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $level;
            }
        };

        $filter = new IpAddressFilter(false, $logger);

        static::assertSame(
            [ '93.184.216.34', '2a00:1450:4001:816::200e' ],
            $filter->filter([ '93.184.216.34', '2A00:1450:4001:0816::200E', '93.184.216.34', '10.0.0.1', '1.2.3.0/24' ], 'office.example.net'),
        );
        static::assertSame([ 'warning', 'warning' ], $logger->messages);
    }
}
