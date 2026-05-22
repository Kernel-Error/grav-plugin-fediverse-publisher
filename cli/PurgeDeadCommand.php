<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\FediversePublisher\Push\OutboundQueue;
use Grav\Plugin\FediversePublisher\Storage\Database;
use Symfony\Component\Console\Input\InputOption;

/**
 * `bin/plugin fediverse-publisher push:purge-dead [--older-than=N]`
 *
 * Operator housekeeping: drop terminal `dead` rows from push_queue.
 *
 * Rows reach `dead` after the retry loop gives up — permanent HTTP
 * 4xx (excluding 410 Gone, which removes the follower instead),
 * 5×404 in 14 days, or attempt-count exhaustion. They never get
 * retried, but they accumulate forever otherwise. v0.0.6 production
 * SQLite still carried two dead rows from the v0.0.3/v0.0.4 deploys
 * (both signed with the broken `http://localhost` keyId, no path
 * back to legitimacy).
 *
 * Without `--older-than`, removes every dead row regardless of age.
 * With `--older-than=N`, removes only rows whose `updated_at` is
 * more than N days old — useful as a cron-friendly variant that
 * keeps recent failures visible for debugging.
 */
final class PurgeDeadCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('push:purge-dead')
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Only purge dead rows whose updated_at is older than N days. '
                . 'Omit to purge all dead rows.',
                '0'
            )
            ->setDescription('Drop terminal dead rows from the push queue (housekeeping).');
    }

    protected function serve(): int
    {
        require_once \dirname(__DIR__) . '/vendor/autoload.php';

        $grav = Grav::instance();
        if (!\extension_loaded('pdo_sqlite')) {
            $this->output->writeln('<error>pdo_sqlite extension not loaded; plugin cannot run.</error>');
            return 1;
        }

        $olderThanDaysRaw = (string) $this->input->getOption('older-than');
        if (!ctype_digit($olderThanDaysRaw)) {
            $this->output->writeln('<error>--older-than must be a non-negative integer number of days.</error>');
            return 1;
        }
        $olderThanSeconds = ((int) $olderThanDaysRaw) * 86400;

        $locator  = $grav['locator'];
        $userData = (string) $locator->findResource('user-data://', true);
        $dbPath   = $userData . '/fediverse-publisher/fediverse-publisher.sqlite';

        $pdo = Database::connect($dbPath);
        Database::migrate($pdo);

        $queue = new OutboundQueue($pdo);
        $removed = $queue->purgeDead($olderThanSeconds);

        if ($olderThanSeconds > 0) {
            $this->output->writeln(\sprintf(
                '<info>Removed %d dead push_queue rows older than %d day(s).</info>',
                $removed,
                (int) $olderThanDaysRaw
            ));
        } else {
            $this->output->writeln(\sprintf(
                '<info>Removed %d dead push_queue rows.</info>',
                $removed
            ));
        }
        return 0;
    }
}
