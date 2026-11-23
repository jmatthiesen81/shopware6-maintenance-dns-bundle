<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Sync;

use Devable\MaintenanceDnsBundle\Dns\DnsResult;
use Devable\MaintenanceDnsBundle\Sync\ManagedIp;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class SyncPlannerTest extends TestCase
{
    private const SC = '0190a0b0c0d0e0f00000000000000001';
    private const HOST = 'office.example.net';
    private const NOW = 1_800_000_000;

    public function testAddsResolvedIpAndRecordsOwnership(): void
    {
        $plan = (new SyncPlanner())->plan(self::SC, null, [], [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertSame([ '81.2.69.10' ], $plan->allowlist);
        static::assertTrue($plan->allowlistChanged);
        static::assertSame([ '81.2.69.10' ], $plan->added);
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ], $plan->upserts);
        static::assertSame([], $plan->warnings);
    }

    public function testKeepsExistingEntriesAndOrder(): void
    {
        $plan = (new SyncPlanner())->plan(self::SC, [ '85.10.20.1', '10.0.0.0/8' ], [], [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertSame([ '85.10.20.1', '10.0.0.0/8', '81.2.69.10' ], $plan->allowlist);
    }

    public function testIpChangeReplacesOnlyManagedIp(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '85.10.20.1', '81.2.69.10' ], $managed, [ self::HOST ], $this->success(self::HOST, '81.2.69.20'));

        static::assertSame([ '85.10.20.1', '81.2.69.20' ], $plan->allowlist);
        static::assertSame([ '81.2.69.20' ], $plan->added);
        static::assertSame([ '81.2.69.10' ], $plan->removed);
        static::assertSame([ '81.2.69.10' ], $plan->deletes);
    }

    public function testManualEntryIsNeverRemovedEvenIfManaged(): void
    {
        // The IP was entered manually before the hostname resolved to it
        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10' ], [], [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertFalse($plan->allowlistChanged);
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], false) ], $plan->upserts);

        // Later the hostname points elsewhere
        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10' ], $plan->upserts, [ self::HOST ], $this->success(self::HOST, '81.2.69.20'));

        static::assertSame([ '81.2.69.10', '81.2.69.20' ], $plan->allowlist);
        static::assertSame([], $plan->removed);
        static::assertSame([ '81.2.69.10' ], $plan->deletes);
    }

    public function testManualEntryInOtherNotationIsRecognized(): void
    {
        $plan = (new SyncPlanner())->plan(self::SC, [ '2A00:1450:4001:0816:0000:0000:0000:200E' ], [], [ self::HOST ], $this->success(self::HOST, '2a00:1450:4001:816::200e'));

        static::assertFalse($plan->allowlistChanged);
        static::assertFalse($plan->upserts['2a00:1450:4001:816::200e']->inAllowlist);
    }

    public function testUnchangedResultIsIdempotent(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertFalse($plan->hasChanges());
        static::assertSame([ '81.2.69.10' ], $plan->allowlist);
    }

    public function testDnsFailureKeepsManagedIps(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '85.10.20.1', '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::failure(self::HOST, 'timeout') ]);

        static::assertFalse($plan->hasChanges());
        static::assertSame([ '85.10.20.1', '81.2.69.10' ], $plan->allowlist);
        static::assertSame([ 'office.example.net: timeout, keeping 81.2.69.10.' ], $plan->warnings);
    }

    public function testDnsFailureWithoutManagedIpsAddsNothing(): void
    {
        $plan = (new SyncPlanner())->plan(self::SC, [ '85.10.20.1' ], [], [ self::HOST ], [ self::HOST => DnsResult::failure(self::HOST, 'SERVFAIL') ]);

        static::assertFalse($plan->hasChanges());
        static::assertSame([ 'office.example.net: SERVFAIL, nothing to keep.' ], $plan->warnings);
    }

    public function testNegativeAnswerKeepsManagedIpsByDefault(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::nxDomain(self::HOST) ]);

        static::assertFalse($plan->hasChanges());
    }

    public function testNegativeAnswerRemovesManagedIpsWhenConfigured(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner(true))->plan(self::SC, [ '85.10.20.1', '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::noData(self::HOST) ]);

        static::assertSame([ '85.10.20.1' ], $plan->allowlist);
        static::assertSame([ '81.2.69.10' ], $plan->removed);
    }

    public function testFailureIsNeverRemovingEvenWhenNegativeAnswersRemove(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner(true))->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::failure(self::HOST, 'timeout') ]);

        static::assertFalse($plan->hasChanges());
    }

    public function testRemovingLastEntryResultsInNull(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10' ], $managed, [], []);

        static::assertNull($plan->allowlist);
        static::assertSame([ '81.2.69.10' ], $plan->removed);
    }

    public function testUnconfiguredHostIsDropped(): void
    {
        $managed = [
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'old.example.net', self::HOST ], true),
            '85.10.20.1' => new ManagedIp('85.10.20.1', [ 'old.example.net' ], true),
        ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10', '85.10.20.1' ], $managed, [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertSame([ '81.2.69.10' ], $plan->allowlist);
        static::assertSame([ '85.10.20.1' ], $plan->removed);
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ], $plan->upserts);
    }

    public function testSharedIpOfTwoHostsStaysWhileOneResolvesToIt(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'agency.example.org', self::HOST ], true) ];
        $results = $this->success(self::HOST, '81.2.69.20') + $this->success('agency.example.org', '81.2.69.10');

        $plan = (new SyncPlanner())->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST, 'agency.example.org' ], $results);

        static::assertSame([ '81.2.69.10', '81.2.69.20' ], $plan->allowlist);
        static::assertSame([], $plan->removed);
        static::assertSame([ 'agency.example.org' ], $plan->upserts['81.2.69.10']->hostnames);
    }

    public function testMultipleIpsAndHostsAreDeduplicated(): void
    {
        $results = [
            self::HOST => DnsResult::success(self::HOST, [ '81.2.69.10', '2a00:1450:4001:816::200e' ]),
            'agency.example.org' => DnsResult::success('agency.example.org', [ '81.2.69.10', '85.10.20.1' ]),
        ];

        $plan = (new SyncPlanner())->plan(self::SC, null, [], [ self::HOST, 'agency.example.org' ], $results);

        static::assertSame([ '2a00:1450:4001:816::200e', '81.2.69.10', '85.10.20.1' ], $plan->allowlist);
        static::assertSame([ 'agency.example.org', self::HOST ], $plan->upserts['81.2.69.10']->hostnames);
    }

    public function testManagedIpRemovedByAdminIsAddedAgain(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '85.10.20.1' ], $managed, [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertSame([ '85.10.20.1', '81.2.69.10' ], $plan->allowlist);
        static::assertCount(1, $plan->warnings);
    }

    public function testManualEntryRemovedByAdminIsTakenOver(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], false) ];

        $plan = (new SyncPlanner())->plan(self::SC, null, $managed, [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertSame([ '81.2.69.10' ], $plan->allowlist);
        static::assertTrue($plan->upserts['81.2.69.10']->inAllowlist);
    }

    public function testManagedIpRemovedByAdminIsNotRemovedTwice(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = (new SyncPlanner())->plan(self::SC, [ '85.10.20.1' ], $managed, [ self::HOST ], $this->success(self::HOST, '81.2.69.20'));

        static::assertSame([ '85.10.20.1', '81.2.69.20' ], $plan->allowlist);
        static::assertSame([], $plan->removed);
        static::assertSame([ '81.2.69.10' ], $plan->deletes);
    }

    public function testKeepMaxAgeKeepsYoungIpsOnFailure(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW - 3599) ];

        $plan = $this->planner(3600)->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::failure(self::HOST, 'timeout') ]);

        static::assertFalse($plan->hasChanges());
        static::assertSame([ 'office.example.net: timeout, keeping 81.2.69.10.' ], $plan->warnings);
    }

    public function testKeepMaxAgeDropsExpiredIpsOnFailure(): void
    {
        $managed = [
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW - 3600),
            '81.2.69.20' => new ManagedIp('81.2.69.20', [ self::HOST ], true, self::NOW - 60),
            '85.10.20.1' => new ManagedIp('85.10.20.1', [ self::HOST ], false, self::NOW - 7200),
        ];

        $plan = $this->planner(3600)->plan(self::SC, [ '85.10.20.1', '81.2.69.10', '81.2.69.20' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::nxDomain(self::HOST) ]);

        static::assertSame([ '85.10.20.1', '81.2.69.20' ], $plan->allowlist, 'manual entries stay even when expired');
        static::assertSame([ '81.2.69.10' ], $plan->removed);
        static::assertSame([ '81.2.69.10', '85.10.20.1' ], $plan->deletes);
        static::assertSame([], $plan->upserts);
        static::assertSame([ 'office.example.net: NXDOMAIN, keeping 81.2.69.20, not keeping 81.2.69.10, 85.10.20.1 (last resolved more than 3600s ago).' ], $plan->warnings);
    }

    public function testKeepMaxAgeStartsAgeOfIpsWithoutTimestamp(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ];

        $plan = $this->planner(3600)->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::failure(self::HOST, 'timeout') ]);

        static::assertSame([], $plan->deletes);
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW) ], $plan->upserts);
    }

    public function testKeepMaxAgeRefreshesTimestampAfterATenth(): void
    {
        $fresh = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW - 359) ];
        $plan = $this->planner(3600)->plan(self::SC, [ '81.2.69.10' ], $fresh, [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));
        static::assertFalse($plan->hasChanges(), 'no write on every run');

        $due = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW - 360) ];
        $plan = $this->planner(3600)->plan(self::SC, [ '81.2.69.10' ], $due, [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW) ], $plan->upserts);
        static::assertFalse($plan->allowlistChanged);
    }

    public function testKeepMaxAgeStampsNewlyResolvedIps(): void
    {
        $plan = $this->planner(3600)->plan(self::SC, null, [], [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));

        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW) ], $plan->upserts);
    }

    public function testExpiredIpStaysWhileAnotherHostResolvesToIt(): void
    {
        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'agency.example.org', self::HOST ], true, self::NOW - 7200) ];
        $results = [ 'agency.example.org' => DnsResult::failure('agency.example.org', 'timeout') ] + $this->success(self::HOST, '81.2.69.10');

        $plan = $this->planner(3600)->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST, 'agency.example.org' ], $results);

        static::assertSame([], $plan->removed);
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW) ], $plan->upserts);
    }

    public function testWithoutKeepMaxAgeTimestampsAreNeitherWrittenNorEnforced(): void
    {
        $plan = $this->planner(0)->plan(self::SC, null, [], [ self::HOST ], $this->success(self::HOST, '81.2.69.10'));
        static::assertEquals([ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true) ], $plan->upserts);

        $managed = [ '81.2.69.10' => new ManagedIp('81.2.69.10', [ self::HOST ], true, self::NOW - 86400 * 365) ];
        $plan = $this->planner(0)->plan(self::SC, [ '81.2.69.10' ], $managed, [ self::HOST ], [ self::HOST => DnsResult::failure(self::HOST, 'timeout') ]);
        static::assertFalse($plan->hasChanges());
    }

    private function planner(int $keepMaxAge): SyncPlanner
    {
        return new SyncPlanner(false, $keepMaxAge, new MockClock(new \DateTimeImmutable('@' . self::NOW)));
    }

    /**
     * @return array<string, DnsResult>
     */
    private function success(string $hostname, string ...$ips): array
    {
        return [ $hostname => DnsResult::success($hostname, \array_values($ips)) ];
    }
}
