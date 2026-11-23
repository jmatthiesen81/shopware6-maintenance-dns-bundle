<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Runtime;

use Devable\MaintenanceDnsBundle\Dns\DnsResult;
use Devable\MaintenanceDnsBundle\Runtime\CachedHostResolver;
use Devable\MaintenanceDnsBundle\Tests\StubDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

class CachedHostResolverTest extends TestCase
{
    private const HOST = 'office.example.net';

    private StubDnsResolver $dns;

    private ArrayAdapter $cache;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->dns = new StubDnsResolver();
        $this->cache = new ArrayAdapter();
        $this->clock = new MockClock('2026-11-23 12:00:00');
    }

    public function testSuccessfulLookupIsCachedForTtl(): void
    {
        $this->dns->succeed(self::HOST, [ '81.2.69.10' ]);
        $resolver = $this->resolver();

        static::assertSame([ '81.2.69.10' ], $resolver->getIps(self::HOST));
        $this->clock->sleep(299);
        static::assertSame([ '81.2.69.10' ], $resolver->getIps(self::HOST));
        static::assertSame(1, $this->dns->totalCalls());
    }

    public function testChangedIpIsPickedUpAfterTtl(): void
    {
        $this->dns->succeed(self::HOST, [ '81.2.69.10' ]);
        $resolver = $this->resolver();
        $resolver->getIps(self::HOST);

        $this->dns->succeed(self::HOST, [ '81.2.69.20' ]);
        $this->clock->sleep(300);

        static::assertSame([ '81.2.69.20' ], $resolver->getIps(self::HOST));
        static::assertSame(2, $this->dns->totalCalls());
    }

    public function testCacheIsSharedBetweenInstances(): void
    {
        $this->dns->succeed(self::HOST, [ '81.2.69.10' ]);
        $this->resolver()->getIps(self::HOST);

        static::assertSame([ '81.2.69.10' ], $this->resolver()->getIps(self::HOST));
        static::assertSame(1, $this->dns->totalCalls());
    }

    public function testFailureIsFailClosedAndCachedForNegativeTtl(): void
    {
        $this->dns->fail(self::HOST);
        $resolver = $this->resolver();

        static::assertSame([], $resolver->getIps(self::HOST));
        $this->clock->sleep(29);
        static::assertSame([], $resolver->getIps(self::HOST));
        static::assertSame(1, $this->dns->totalCalls());

        $this->clock->sleep(1);
        $resolver->getIps(self::HOST);
        static::assertSame(2, $this->dns->totalCalls());
    }

    public function testFailClosedIgnoresLastSuccessfulAnswer(): void
    {
        $this->dns->succeed(self::HOST, [ '81.2.69.10' ]);
        $resolver = $this->resolver(failClosed: true, staleMaxAge: 3600);
        $resolver->getIps(self::HOST);

        $this->dns->fail(self::HOST);
        $this->clock->sleep(300);

        static::assertSame([], $resolver->getIps(self::HOST));
    }

    public function testStaleAnswerIsUsedWithinMaxAgeWhenNotFailClosed(): void
    {
        $this->dns->succeed(self::HOST, [ '81.2.69.10' ]);
        $resolver = $this->resolver(failClosed: false, staleMaxAge: 900);
        $resolver->getIps(self::HOST);

        $this->dns->fail(self::HOST);
        $this->clock->sleep(300);
        static::assertSame([ '81.2.69.10' ], $resolver->getIps(self::HOST));

        $this->clock->sleep(599);
        static::assertSame([ '81.2.69.10' ], $resolver->getIps(self::HOST));

        // Last success was 900s ago
        $this->clock->sleep(1);
        static::assertSame([], $resolver->getIps(self::HOST));
    }

    public function testNxDomainAllowsNothing(): void
    {
        $this->dns->nxDomain(self::HOST);

        static::assertSame([], $this->resolver(failClosed: false, staleMaxAge: 900)->getIps(self::HOST));
    }

    public function testWarmPreventsLookup(): void
    {
        $resolver = $this->resolver();
        $resolver->warm(DnsResult::success(self::HOST, [ '81.2.69.10' ]));

        static::assertSame([ '81.2.69.10' ], $resolver->getIps(self::HOST));
        static::assertSame(0, $this->dns->totalCalls());
    }

    public function testWarmWithFailureKeepsLastSuccessfulAnswer(): void
    {
        $resolver = $this->resolver(failClosed: false, staleMaxAge: 900);
        $resolver->warm(DnsResult::success(self::HOST, [ '81.2.69.10' ]));
        $this->clock->sleep(60);
        $resolver->warm(DnsResult::failure(self::HOST, 'timeout'));

        static::assertSame([ '81.2.69.10' ], $resolver->getIps(self::HOST));
        static::assertSame(0, $this->dns->totalCalls());
    }

    public function testBrokenCacheEntryIsIgnored(): void
    {
        $this->dns->succeed(self::HOST, [ '81.2.69.10' ]);
        $item = $this->cache->getItem('devable_maintenance_dns_' . \hash('xxh128', self::HOST));
        $item->set([ 'status' => 'bogus', 'ips' => [ '1.1.1.1' ], 'resolvedAt' => \PHP_INT_MAX ]);
        $this->cache->save($item);

        static::assertSame([ '81.2.69.10' ], $this->resolver()->getIps(self::HOST));
    }

    private function resolver(bool $failClosed = true, int $staleMaxAge = 0): CachedHostResolver
    {
        return new CachedHostResolver($this->dns, $this->cache, 300, 30, $failClosed, $staleMaxAge, clock: $this->clock);
    }
}
