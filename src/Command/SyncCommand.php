<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Command;

use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Devable\MaintenanceDnsBundle\Sync\SalesChannelSyncResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: self::NAME,
    description: 'Resolves the configured DNS hostnames and synchronizes their IPs into the maintenance allowlist of the sales channels',
)]
class SyncCommand extends Command
{
    public const NAME = 'maintenance:dns-whitelist:sync';

    /**
     * Exit codes for cron monitoring
     */
    public const EXIT_SUCCESS = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_INVALID = 2;
    public const EXIT_DNS_WARNING = 3;
    public const EXIT_LOCKED = 4;

    public const LOCK_NAME = 'devable-maintenance-dns-whitelist-sync';

    public function __construct(
        private readonly AllowlistSynchronizer $synchronizer,
        private readonly LockFactory $lockFactory,
        private readonly bool $dryRunByDefault = false,
        private readonly int $lockTtl = 300,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sales-channel', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only synchronize this sales channel ID (repeatable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes without writing them')
            ->setHelp(<<<'HELP'
                Resolves the DNS hostnames configured per sales channel and adds their IPs to the maintenance allowlist.
                IPs that the bundle added earlier and that no hostname resolves to any more are removed again. Manually
                entered allowlist entries are never removed. On DNS errors the previously managed IPs are kept.

                The command does not switch the maintenance mode on or off.

                Exit codes:
                  0  success
                  1  error (database, unknown sales channel, …)
                  2  invalid input
                  3  finished, but at least one hostname could not be resolved
                  4  another sync is running (lock held)
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $this->dryRunByDefault || (bool) $input->getOption('dry-run');

        /** @var list<string> $selected */
        $selected = \array_map('strtolower', (array) $input->getOption('sales-channel'));
        foreach ($selected as $salesChannelId) {
            if (!Uuid::isValid($salesChannelId)) {
                $io->error(\sprintf('"%s" is not a valid sales channel ID.', $salesChannelId));

                return self::EXIT_INVALID;
            }
        }

        $known = $this->synchronizer->getSalesChannelIds();
        $unknown = \array_diff($selected, $known);
        if ([] !== $unknown) {
            $io->error(\sprintf('Sales channel(s) %s are not configured.', \implode(', ', $unknown)));

            return self::EXIT_INVALID;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, $this->lockTtl);
        if (!$lock->acquire()) {
            $io->warning('Another maintenance DNS allowlist sync is running, aborting.');

            return self::EXIT_LOCKED;
        }

        try {
            if ($dryRun) {
                $io->note('Dry run, nothing is written.');
            }

            $results = [];
            foreach ([] !== $selected ? $selected : $known as $salesChannelId) {
                $results[] = $result = $this->synchronizer->sync($salesChannelId, $dryRun);
                $this->render($io, $result, $dryRun);
            }
        } finally {
            $lock->release();
        }

        return $this->exitCode($results);
    }

    /**
     * @param list<SalesChannelSyncResult> $results
     */
    private function exitCode(array $results): int
    {
        $code = self::EXIT_SUCCESS;
        foreach ($results as $result) {
            if (null !== $result->error) {
                return self::EXIT_ERROR;
            }

            if ($result->hasDnsProblems()) {
                $code = self::EXIT_DNS_WARNING;
            }
        }

        return $code;
    }

    private function render(SymfonyStyle $io, SalesChannelSyncResult $result, bool $dryRun): void
    {
        $io->section('Sales channel ' . $result->salesChannelId);

        if ($io->isVerbose()) {
            $rows = [];
            foreach ($result->dnsResults as $hostname => $dnsResult) {
                $rows[] = [ $hostname, $dnsResult->status->value, $dnsResult->isSuccess() ? \implode(', ', $dnsResult->ips) : (string) $dnsResult->error ];
            }

            if ([] !== $rows) {
                $io->table([ 'Hostname', 'Status', 'IPs / error' ], $rows);
            }
        }

        if (null !== $result->error) {
            $io->error($result->error);

            return;
        }

        $plan = $result->plan;
        if (null === $plan) {
            return;
        }

        foreach ($plan->warnings as $warning) {
            $io->warning($warning);
        }

        $prefix = $dryRun ? 'Would add' : 'Added';
        foreach ($plan->added as $ip) {
            $io->writeln(\sprintf('  <info>+</info> %s %s', $prefix, $ip));
        }

        $prefix = $dryRun ? 'Would remove' : 'Removed';
        foreach ($plan->removed as $ip) {
            $io->writeln(\sprintf('  <comment>-</comment> %s %s', $prefix, $ip));
        }

        if (!$plan->allowlistChanged) {
            $io->writeln('  Allowlist is up to date.');
        }

        if ($io->isVerbose()) {
            $io->writeln('  Allowlist: ' . \implode(', ', $plan->allowlist ?? []));
        }
    }
}
