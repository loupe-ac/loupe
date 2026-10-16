<?php

declare(strict_types=1);

namespace App\Observability\Command\Console;

use App\Observability\Command\ReportQueueDepthCommand;
use App\Observability\Command\ReportQueueDepthHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the queue gauges once. The scheduler runs the same work every minute;
 * this is the manual backstop and the way to check what Sentry receives.
 */
#[AsCommand(
    name: 'app:report-queue-depth',
    description: 'Send the depth of each messenger queue to Sentry.',
)]
final class ReportQueueDepthConsoleCommand extends Command
{
    public function __construct(
        private readonly ReportQueueDepthHandler $reportQueueDepth,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $depths = ($this->reportQueueDepth)(new ReportQueueDepthCommand());

        if ([] === $depths) {
            $io->warning('No usable SENTRY_DSN, so nothing was sent.');

            return Command::FAILURE;
        }

        $io->table(
            ['Queue', 'Transport', 'Waiting', 'Oldest age (s)'],
            array_map(static fn ($d): array => [$d->queueName, $d->transport, $d->pending, $d->oldestAgeSeconds], $depths),
        );
        $io->success(\sprintf('Sent the gauges of %d queue(s).', \count($depths)));

        return Command::SUCCESS;
    }
}
