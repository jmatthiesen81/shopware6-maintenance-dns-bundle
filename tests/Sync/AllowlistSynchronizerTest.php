<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Sync;

use Devable\MaintenanceDnsBundle\Runtime\CachedHostResolver;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\ManagedIp;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use Devable\MaintenanceDnsBundle\Tests\StubDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Acceptance criteria 1–5 and 10 of the persistent synchronization.
 */
class AllowlistSynchronizerTest extends TestCase
{
    private const SC1 = '0190a0b0c0d0e0f00000000000000001';
    private const SC2 = '0190a0b0c0d0e0f00000000000000002';

    private StubDnsResolver $dns;

    private InMemoryAllowlistGateway $gateway;

    private InMemoryManagedIpStore $store;

    protected function setUp(): void
    {
        $this->dns = (new StubDnsResolver())
            ->succeed('office.example.net', [ '81.2.69.10' ])
            ->succeed('agency.example.org', [ '85.10.20.1', '85.10.20.2' ])
            ->succeed('qa.example.net', [ '81.2.69.30' ]);

        $this->gateway = new InMemoryAllowlistGateway([
            self::SC1 => [ '1.1.1.1' ],
            self::SC2 => null,
        ]);
        $this->store = new InMemoryManagedIpStore();
    }

    public function testMultipleSalesChannelsHostsAndIps(): void
    {
        $synchronizer = $this->synchronizer();

        foreach ($synchronizer->getSalesChannelIds() as $salesChannelId) {
            $synchronizer->sync($salesChannelId);
        }

        static::assertSame([ '1.1.1.1', '81.2.69.10', '85.10.20.1', '85.10.20.2' ], $this->gateway->allowlists[self::SC1]);
        static::assertSame([ '81.2.69.30' ], $this->gateway->allowlists[self::SC2]);
    }

    public function testChangedIpReplacesOnlyTheOldManagedIp(): void
    {
        $synchronizer = $this->synchronizer();
        $synchronizer->sync(self::SC1);

        $this->dns->succeed('office.example.net', [ '81.2.69.11' ]);
        $result = $synchronizer->sync(self::SC1);

        static::assertSame([ '1.1.1.1', '85.10.20.1', '85.10.20.2', '81.2.69.11' ], $this->gateway->allowlists[self::SC1]);
        static::assertSame([ '81.2.69.11' ], $result->plan?->added);
        static::assertSame([ '81.2.69.10' ], $result->plan?->removed);
    }

    public function testManualEntriesSurviveDnsErrorsAndIpChanges(): void
    {
        $this->gateway->allowlists[self::SC1] = [ '1.1.1.1', '81.2.69.10' ];
        $synchronizer = $this->synchronizer();
        $synchronizer->sync(self::SC1);

        $this->dns->fail('office.example.net')->fail('agency.example.org');
        $result = $synchronizer->sync(self::SC1);
        static::assertTrue($result->hasDnsProblems());
        static::assertSame([ '1.1.1.1', '81.2.69.10', '85.10.20.1', '85.10.20.2' ], $this->gateway->allowlists[self::SC1]);

        $this->dns->succeed('office.example.net', [ '81.2.69.99' ])->succeed('agency.example.org', [ '85.10.20.1' ]);
        $synchronizer->sync(self::SC1);
        static::assertSame([ '1.1.1.1', '81.2.69.10', '85.10.20.1', '81.2.69.99' ], $this->gateway->allowlists[self::SC1]);
    }

    public function testRepeatedRunIsIdempotent(): void
    {
        $synchronizer = $this->synchronizer();
        $synchronizer->sync(self::SC1);
        $saves = $this->gateway->saves;
        $writes = $this->store->writes;

        $result = $synchronizer->sync(self::SC1);

        static::assertFalse($result->plan?->hasChanges());
        static::assertSame($saves, $this->gateway->saves);
        static::assertSame($writes, $this->store->writes);
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->synchronizer()->sync(self::SC1, true);

        static::assertSame([ '81.2.69.10', '85.10.20.1', '85.10.20.2' ], $result->plan?->added);
        static::assertSame([ '1.1.1.1' ], $this->gateway->allowlists[self::SC1]);
        static::assertSame(0, $this->gateway->saves);
        static::assertSame(0, $this->store->writes);
    }

    public function testRemovedSalesChannelConfigurationCleansUp(): void
    {
        $this->synchronizer()->sync(self::SC2);
        static::assertSame([ '81.2.69.30' ], $this->gateway->allowlists[self::SC2]);

        $synchronizer = $this->synchronizer(new SalesChannelHosts([ self::SC1 => [ 'office.example.net' ] ]));
        static::assertSame([ self::SC1, self::SC2 ], $synchronizer->getSalesChannelIds());

        $synchronizer->sync(self::SC2);
        static::assertNull($this->gateway->allowlists[self::SC2]);
        static::assertSame([], $this->store->load(self::SC2));
    }

    public function testErrorRollsBackAndIsReported(): void
    {
        $this->gateway->failOnSave = true;

        $result = $this->synchronizer()->sync(self::SC1);

        static::assertSame('Deadlock found when trying to get lock', $result->error);
        static::assertSame([ '1.1.1.1' ], $this->gateway->allowlists[self::SC1]);
        static::assertSame(0, $this->store->writes);
    }

    public function testUnknownSalesChannelIsReported(): void
    {
        unset($this->gateway->allowlists[self::SC1]);

        $result = $this->synchronizer()->sync(self::SC1);

        static::assertSame('Sales channel ' . self::SC1 . ' does not exist.', $result->error);
    }

    public function testWarmsRuntimeCache(): void
    {
        $cache = new ArrayAdapter();
        $runtimeDns = new StubDnsResolver();
        $runtime = new CachedHostResolver($runtimeDns, $cache);

        $this->synchronizer(runtimeCache: $runtime)->sync(self::SC1, true);

        static::assertSame([ '81.2.69.10' ], $runtime->getIps('office.example.net'));
        static::assertSame(0, $runtimeDns->totalCalls());
    }

    public function testRuntimeCacheDoesNotInfluencePersistentState(): void
    {
        $cache = new ArrayAdapter();
        $runtime = new CachedHostResolver((new StubDnsResolver())->succeed('office.example.net', [ '81.2.69.77' ]), $cache);
        $runtime->getIps('office.example.net');

        $this->synchronizer(runtimeCache: $runtime)->sync(self::SC1);

        static::assertNotContains('81.2.69.77', $this->gateway->allowlists[self::SC1] ?? []);
        static::assertSame(1, $this->dns->calls['office.example.net']);
    }

    public function testDeletedSalesChannelThatIsNoLongerConfiguredIsForgotten(): void
    {
        $this->synchronizer()->sync(self::SC2);
        unset($this->gateway->allowlists[self::SC2]);

        $result = $this->synchronizer(new SalesChannelHosts([ self::SC1 => [ 'office.example.net' ] ]))->sync(self::SC2);

        static::assertNull($result->error);
        static::assertSame([], $this->store->getSalesChannelIds());
    }

    public function testCleanupRemovesOnlyBundleAddedIpsAndState(): void
    {
        $this->gateway->allowlists[self::SC1] = [ '1.1.1.1', '85.10.20.1' ];
        $synchronizer = $this->synchronizer();
        $synchronizer->sync(self::SC1);
        $synchronizer->sync(self::SC2);
        static::assertSame([ self::SC1, self::SC2 ], $synchronizer->getCleanupSalesChannelIds());

        $dryRun = $synchronizer->cleanup(self::SC1, true);
        static::assertSame([ '81.2.69.10', '85.10.20.2' ], $dryRun->plan?->removed);
        static::assertSame([ '1.1.1.1', '85.10.20.1', '81.2.69.10', '85.10.20.2' ], $this->gateway->allowlists[self::SC1]);

        foreach ($synchronizer->getCleanupSalesChannelIds() as $salesChannelId) {
            $synchronizer->cleanup($salesChannelId);
        }

        static::assertSame([ '1.1.1.1', '85.10.20.1' ], $this->gateway->allowlists[self::SC1]);
        static::assertNull($this->gateway->allowlists[self::SC2]);
        static::assertSame([], $synchronizer->getCleanupSalesChannelIds());
        // Cleanup does not resolve anything
        static::assertSame(3, $this->dns->totalCalls());
    }

    public function testStoredOwnership(): void
    {
        $this->gateway->allowlists[self::SC1] = [ '85.10.20.1' ];

        $this->synchronizer()->sync(self::SC1);

        static::assertEquals([
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'office.example.net' ], true),
            '85.10.20.1' => new ManagedIp('85.10.20.1', [ 'agency.example.org' ], false),
            '85.10.20.2' => new ManagedIp('85.10.20.2', [ 'agency.example.org' ], true),
        ], $this->store->load(self::SC1));
    }

    private function synchronizer(SalesChannelHosts|null $hosts = null, CachedHostResolver|null $runtimeCache = null): AllowlistSynchronizer
    {
        return new AllowlistSynchronizer(
            $hosts ?? new SalesChannelHosts([
                self::SC1 => [ 'office.example.net', 'agency.example.org' ],
                self::SC2 => [ 'qa.example.net' ],
            ]),
            $this->dns,
            $this->gateway,
            $this->store,
            new SyncPlanner(),
            runtimeCache: $runtimeCache,
        );
    }
}
