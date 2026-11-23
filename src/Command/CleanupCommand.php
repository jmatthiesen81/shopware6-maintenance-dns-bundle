<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Command;

use Devable\MaintenanceDnsBundle\Sync\AllowlistSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * A Symfony bundle has no uninstall hook. Run this command before removing the bundle, so neither the IPs it
 * added to the allowlists nor its state in the system configuration stay behind.
 */
#[AsCommand(
    name: self::NAME,
    description: 'Removes the IPs the bundle added from the maintenance allowlists and deletes its state (run before uninstalling)',
)]
class CleanupCommand extends Command
{
    public const NAME = 'maintenance:dns-whitelist:cleanup';

    public function __construct(
        private readonly AllowlistSynchronizer $synchronizer,
        private readonly LockFactory $lockFactory,
        private readonly int $lockTtl = 300,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes without writing them')
            ->setHelp(<<<'HELP'
                Removes all IPs that the bundle itself added to the maintenance allowlists of the sales channels and
                deletes the bundle's state from the system configuration. Manually entered allowlist entries stay.

                Run it before removing the bundle from config/bundles.php. Disable the cron job first; if the sync
                command runs again afterwards, it adds the IPs again.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $lock = $this->lockFactory->createLock(SyncCommand::LOCK_NAME, $this->lockTtl);
        if (!$lock->acquire()) {
            $io->warning('A maintenance DNS allowlist sync is running, aborting.');

            return SyncCommand::EXIT_LOCKED;
        }

        $code = SyncCommand::EXIT_SUCCESS;

        try {
            $salesChannelIds = $this->synchronizer->getCleanupSalesChannelIds();
            if ([] === $salesChannelIds) {
                $io->success('Nothing to clean up.');

                return SyncCommand::EXIT_SUCCESS;
            }

            foreach ($salesChannelIds as $salesChannelId) {
                $result = $this->synchronizer->cleanup($salesChannelId, $dryRun);
                $io->section('Sales channel ' . $salesChannelId);

                if (null !== $result->error) {
                    $io->error($result->error);
                    $code = SyncCommand::EXIT_ERROR;

                    continue;
                }

                foreach ($result->plan->removed ?? [] as $ip) {
                    $io->writeln(\sprintf('  <comment>-</comment> %s %s', $dryRun ? 'Would remove' : 'Removed', $ip));
                }

                $io->writeln($dryRun ? '  State would be deleted.' : '  State deleted.');
            }
        } finally {
            $lock->release();
        }

        return $code;
    }
}
