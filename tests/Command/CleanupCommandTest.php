<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Command;

use Devable\MaintenanceDnsBundle\Command\CleanupCommand;
use Devable\MaintenanceDnsBundle\Command\SyncCommand;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\ManagedIp;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use Devable\MaintenanceDnsBundle\Tests\StubDnsResolver;
use Devable\MaintenanceDnsBundle\Tests\Sync\InMemoryAllowlistGateway;
use Devable\MaintenanceDnsBundle\Tests\Sync\InMemoryManagedIpStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class CleanupCommandTest extends TestCase
{
    private const SC = '0190a0b0c0d0e0f00000000000000001';

    private InMemoryAllowlistGateway $gateway;

    private InMemoryManagedIpStore $store;

    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryAllowlistGateway([ self::SC => [ '1.1.1.1', '81.2.69.10', '85.10.20.1' ] ]);
        $this->store = new InMemoryManagedIpStore([ self::SC => [
            '81.2.69.10' => new ManagedIp('81.2.69.10', [ 'office.example.net' ], true),
            '85.10.20.1' => new ManagedIp('85.10.20.1', [ 'agency.example.org' ], false),
        ] ]);
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    public function testRemovesBundleAddedIpsAndStateEvenWhenUnconfigured(): void
    {
        $tester = $this->tester();

        static::assertSame(SyncCommand::EXIT_SUCCESS, $tester->execute([]));
        static::assertSame([ '1.1.1.1', '85.10.20.1' ], $this->gateway->allowlists[self::SC]);
        static::assertSame([], $this->store->records);
        static::assertStringContainsString('- Removed 81.2.69.10', $tester->getDisplay());

        static::assertSame(SyncCommand::EXIT_SUCCESS, $tester->execute([]));
        static::assertStringContainsString('Nothing to clean up.', $tester->getDisplay());
    }

    public function testDryRun(): void
    {
        $tester = $this->tester();

        static::assertSame(SyncCommand::EXIT_SUCCESS, $tester->execute([ '--dry-run' => true ]));
        static::assertSame([ '1.1.1.1', '81.2.69.10', '85.10.20.1' ], $this->gateway->allowlists[self::SC]);
        static::assertCount(2, $this->store->records[self::SC]);
        static::assertStringContainsString('Would remove 81.2.69.10', $tester->getDisplay());
    }

    public function testDeletedSalesChannel(): void
    {
        unset($this->gateway->allowlists[self::SC]);

        static::assertSame(SyncCommand::EXIT_SUCCESS, $this->tester()->execute([]));
        static::assertSame([], $this->store->records);
    }

    public function testSharesTheLockWithSync(): void
    {
        $lock = $this->lockFactory->createLock(SyncCommand::LOCK_NAME);
        $lock->acquire();

        try {
            static::assertSame(SyncCommand::EXIT_LOCKED, $this->tester()->execute([]));
        } finally {
            $lock->release();
        }
    }

    private function tester(): CommandTester
    {
        // The bundle is disabled before uninstalling: no hostnames configured
        $synchronizer = new AllowlistSynchronizer(new SalesChannelHosts([]), new StubDnsResolver(), $this->gateway, $this->store, new SyncPlanner());

        return new CommandTester(new CleanupCommand($synchronizer, $this->lockFactory));
    }
}
