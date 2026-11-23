<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Command;

use Devable\MaintenanceDnsBundle\Command\SyncCommand;
use Devable\MaintenanceDnsBundle\SalesChannelHosts;
use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\SyncPlanner;
use Devable\MaintenanceDnsBundle\Tests\StubDnsResolver;
use Devable\MaintenanceDnsBundle\Tests\Sync\InMemoryAllowlistGateway;
use Devable\MaintenanceDnsBundle\Tests\Sync\InMemoryManagedIpStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class SyncCommandTest extends TestCase
{
    private const SC1 = '0190a0b0c0d0e0f00000000000000001';
    private const SC2 = '0190a0b0c0d0e0f00000000000000002';

    private StubDnsResolver $dns;

    private InMemoryAllowlistGateway $gateway;

    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->dns = (new StubDnsResolver())
            ->succeed('office.example.net', [ '81.2.69.10' ])
            ->succeed('qa.example.net', [ '81.2.69.30' ]);
        $this->gateway = new InMemoryAllowlistGateway([ self::SC1 => [ '1.1.1.1' ], self::SC2 => null ]);
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    public function testSyncsAllSalesChannels(): void
    {
        $tester = $this->tester();

        static::assertSame(SyncCommand::EXIT_SUCCESS, $tester->execute([]));
        static::assertSame([ '1.1.1.1', '81.2.69.10' ], $this->gateway->allowlists[self::SC1]);
        static::assertSame([ '81.2.69.30' ], $this->gateway->allowlists[self::SC2]);
        static::assertStringContainsString('+ Added 81.2.69.10', $tester->getDisplay());

        static::assertSame(SyncCommand::EXIT_SUCCESS, $tester->execute([]));
        static::assertStringContainsString('Allowlist is up to date.', $tester->getDisplay());
    }

    public function testSelectedSalesChannelOnly(): void
    {
        static::assertSame(SyncCommand::EXIT_SUCCESS, $this->tester()->execute([ '--sales-channel' => [ \strtoupper(self::SC2) ] ]));

        static::assertSame([ '1.1.1.1' ], $this->gateway->allowlists[self::SC1]);
        static::assertSame([ '81.2.69.30' ], $this->gateway->allowlists[self::SC2]);
    }

    public function testDryRunOption(): void
    {
        $tester = $this->tester();

        static::assertSame(SyncCommand::EXIT_SUCCESS, $tester->execute([ '--dry-run' => true ]));
        static::assertSame([ '1.1.1.1' ], $this->gateway->allowlists[self::SC1]);
        static::assertStringContainsString('Would add 81.2.69.10', $tester->getDisplay());
    }

    public function testDryRunByConfiguration(): void
    {
        static::assertSame(SyncCommand::EXIT_SUCCESS, $this->tester(dryRun: true)->execute([]));
        static::assertSame([ '1.1.1.1' ], $this->gateway->allowlists[self::SC1]);
    }

    public function testVerboseShowsDnsResults(): void
    {
        $tester = $this->tester();
        $tester->execute([], [ 'verbosity' => OutputInterface::VERBOSITY_VERBOSE ]);

        static::assertMatchesRegularExpression('/office\.example\.net\s+success\s+81\.2\.69\.10/', $tester->getDisplay());
    }

    public function testDnsProblemExitCode(): void
    {
        $this->dns->fail('qa.example.net');
        $tester = $this->tester();

        static::assertSame(SyncCommand::EXIT_DNS_WARNING, $tester->execute([]));
        static::assertSame([ '1.1.1.1', '81.2.69.10' ], $this->gateway->allowlists[self::SC1]);
        static::assertStringContainsString('qa.example.net: timeout, nothing to keep.', $tester->getDisplay());
    }

    public function testErrorExitCode(): void
    {
        unset($this->gateway->allowlists[self::SC2]);

        static::assertSame(SyncCommand::EXIT_ERROR, $this->tester()->execute([]));
        static::assertSame([ '1.1.1.1', '81.2.69.10' ], $this->gateway->allowlists[self::SC1]);
    }

    public function testInvalidSalesChannelId(): void
    {
        static::assertSame(SyncCommand::EXIT_INVALID, $this->tester()->execute([ '--sales-channel' => [ 'abc' ] ]));
    }

    public function testUnconfiguredSalesChannel(): void
    {
        $tester = $this->tester();

        static::assertSame(SyncCommand::EXIT_INVALID, $tester->execute([ '--sales-channel' => [ '0190a0b0c0d0e0f0000000000000ffff' ] ]));
        static::assertStringContainsString('not configured', $tester->getDisplay());
    }

    public function testParallelRunIsAborted(): void
    {
        $lock = $this->lockFactory->createLock('devable-maintenance-dns-whitelist-sync');
        $lock->acquire();

        try {
            static::assertSame(SyncCommand::EXIT_LOCKED, $this->tester()->execute([]));
            static::assertSame([ '1.1.1.1' ], $this->gateway->allowlists[self::SC1]);
        } finally {
            $lock->release();
        }

        static::assertSame(SyncCommand::EXIT_SUCCESS, $this->tester()->execute([]));
    }

    public function testCommandName(): void
    {
        static::assertSame('maintenance:dns-whitelist:sync', $this->command()->getName());
    }

    private function tester(bool $dryRun = false): CommandTester
    {
        return new CommandTester($this->command($dryRun));
    }

    private function command(bool $dryRun = false): SyncCommand
    {
        $synchronizer = new AllowlistSynchronizer(
            new SalesChannelHosts([ self::SC1 => [ 'office.example.net' ], self::SC2 => [ 'qa.example.net' ] ]),
            $this->dns,
            $this->gateway,
            new InMemoryManagedIpStore(),
            new SyncPlanner(),
        );

        return new SyncCommand($synchronizer, $this->lockFactory, $dryRun);
    }
}
