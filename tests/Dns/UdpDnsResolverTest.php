<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Dns;

use Devable\MaintenanceDnsBundle\Dns\DnsMessage;
use Devable\MaintenanceDnsBundle\Dns\DnsStatus;
use Devable\MaintenanceDnsBundle\Dns\UdpDnsResolver;
use Devable\MaintenanceDnsBundle\Ip\IpAddressFilter;
use PHPUnit\Framework\TestCase;

class UdpDnsResolverTest extends TestCase
{
    private const HOST = 'office.example.net';

    public function testResolvesIpv4(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('81.2.69.10'));

        $result = $this->resolver($transport)->resolve(self::HOST);

        static::assertSame(DnsStatus::Success, $result->status);
        static::assertSame([ '81.2.69.10' ], $result->ips);
        static::assertSame([ '192.0.2.53' ], \array_column($transport->queries, 'nameserver'));
    }

    public function testResolvesIpv4AndIpv6Deduplicated(): void
    {
        $transport = (new FakeDnsTransport())
            ->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('81.2.69.10')->a('81.2.69.10')->a('85.10.20.20'))
            ->on(self::HOST, 'AAAA', static fn (DnsResponseBuilder $r) => $r->aaaa('2a00:1450:4001:0816:0000:0000:0000:200e'))
        ;

        $result = $this->resolver($transport, [ 'A', 'AAAA' ])->resolve(self::HOST);

        static::assertSame([ '81.2.69.10', '85.10.20.20', '2a00:1450:4001:816::200e' ], $result->ips);
    }

    public function testTimeoutIsFailure(): void
    {
        $result = $this->resolver(new FakeDnsTransport())->resolve(self::HOST);

        static::assertSame(DnsStatus::Failure, $result->status);
        static::assertSame([], $result->ips);
        static::assertStringContainsString('timed out', (string) $result->error);
    }

    public function testFailureOfOneRecordTypeFailsTheHost(): void
    {
        $transport = (new FakeDnsTransport())
            ->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('81.2.69.10'))
            ->on(self::HOST, 'AAAA', static fn (DnsResponseBuilder $r) => $r->rcode(DnsMessage::RCODE_SERVFAIL))
        ;

        $result = $this->resolver($transport, [ 'A', 'AAAA' ])->resolve(self::HOST);

        static::assertSame(DnsStatus::Failure, $result->status);
        static::assertSame([], $result->ips);
    }

    public function testNxDomain(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->rcode(DnsMessage::RCODE_NXDOMAIN));

        static::assertSame(DnsStatus::NxDomain, $this->resolver($transport)->resolve(self::HOST)->status);
    }

    public function testEmptyAnswerIsNoData(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r);

        static::assertSame(DnsStatus::NoData, $this->resolver($transport)->resolve(self::HOST)->status);
    }

    public function testTruncatedAnswerIsFailure(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->truncated()->a('81.2.69.10'));

        static::assertSame(DnsStatus::Failure, $this->resolver($transport)->resolve(self::HOST)->status);
    }

    public function testPrivateAddressesAreRejected(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('192.168.1.10')->a('127.0.0.1')->a('81.2.69.10'));

        static::assertSame([ '81.2.69.10' ], $this->resolver($transport)->resolve(self::HOST)->ips);
    }

    public function testOnlyRejectedAddressesIsNoData(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('10.0.0.1'));

        $result = $this->resolver($transport)->resolve(self::HOST);

        static::assertSame(DnsStatus::NoData, $result->status);
        static::assertSame('all addresses were rejected', $result->error);
    }

    public function testNonPublicAddressesCanBeAllowed(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('10.0.0.1'));

        $resolver = new UdpDnsResolver($transport, new IpAddressFilter(true), [ 'A' ], 2.0, 10, [ '192.0.2.53' ]);

        static::assertSame([ '10.0.0.1' ], $resolver->resolve(self::HOST)->ips);
    }

    public function testMaxRecords(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('81.2.69.1')->a('81.2.69.2')->a('81.2.69.3'));

        $resolver = new UdpDnsResolver($transport, new IpAddressFilter(), [ 'A' ], 2.0, 2, [ '192.0.2.53' ]);

        static::assertSame([ '81.2.69.1', '81.2.69.2' ], $resolver->resolve(self::HOST)->ips);
    }

    public function testFallsBackToNextNameserverOnServfailOrTimeout(): void
    {
        $transport = (new FakeDnsTransport())
            ->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->rcode(DnsMessage::RCODE_SERVFAIL), '192.0.2.1')
            ->on(self::HOST, 'A', static fn () => 'timeout', '192.0.2.2')
            ->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('81.2.69.10'), '192.0.2.3')
        ;

        $resolver = new UdpDnsResolver($transport, new IpAddressFilter(), [ 'A' ], 2.0, 10, [ '192.0.2.1', '192.0.2.2', '192.0.2.3' ]);

        static::assertSame([ '81.2.69.10' ], $resolver->resolve(self::HOST)->ips);
        static::assertSame([ '192.0.2.1', '192.0.2.2', '192.0.2.3' ], \array_column($transport->queries, 'nameserver'));
    }

    public function testNxDomainIsNotRetriedOnOtherNameservers(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->rcode(DnsMessage::RCODE_NXDOMAIN));

        $resolver = new UdpDnsResolver($transport, new IpAddressFilter(), [ 'A' ], 2.0, 10, [ '192.0.2.1', '192.0.2.2' ]);

        static::assertSame(DnsStatus::NxDomain, $resolver->resolve(self::HOST)->status);
        static::assertCount(1, $transport->queries);
    }

    public function testTimeoutIsTheTotalBudget(): void
    {
        $transport = new FakeDnsTransport();

        (new UdpDnsResolver($transport, new IpAddressFilter(), [ 'A' ], 1.5, 10, [ '192.0.2.1' ]))->resolve(self::HOST);

        static::assertLessThanOrEqual(1.5, $transport->queries[0]['timeout']);
        static::assertGreaterThan(1.4, $transport->queries[0]['timeout']);
    }

    public function testUsesSystemNameserversWhenNoneConfigured(): void
    {
        $transport = (new FakeDnsTransport())->on(self::HOST, 'A', static fn (DnsResponseBuilder $r) => $r->a('81.2.69.10'));

        $resolver = new UdpDnsResolver($transport, new IpAddressFilter(), systemNameservers: static fn (): array => [ '192.0.2.99' ]);
        $resolver->resolve(self::HOST);

        static::assertSame('192.0.2.99', $transport->queries[0]['nameserver']);
    }

    public function testNoNameserverIsFailure(): void
    {
        $resolver = new UdpDnsResolver(new FakeDnsTransport(), new IpAddressFilter(), systemNameservers: static fn (): array => []);

        static::assertSame(DnsStatus::Failure, $resolver->resolve(self::HOST)->status);
    }

    /**
     * @param list<string> $recordTypes
     */
    private function resolver(FakeDnsTransport $transport, array $recordTypes = [ 'A' ]): UdpDnsResolver
    {
        return new UdpDnsResolver($transport, new IpAddressFilter(), $recordTypes, 2.0, 10, [ '192.0.2.53' ]);
    }
}
