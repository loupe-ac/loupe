<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a bucket recompute for every project, so that runs from before the call
 * count and the time with subagents existed get both figures.
 */
#[AsCommand(
    name: 'app:bridge:recompute-bucket-times',
    description: 'Queue a recompute of the bucket figures of every project.',
)]
final class RecomputeBucketTimesConsoleCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $queued = 0;
        foreach ($this->projects->findAll() as $project) {
            $this->bus->dispatch(new RecomputeBucketTimes((string) $project->requireId()));
            ++$queued;
        }

        $io->success(\sprintf('Queued the bucket recompute of %d project(s).', $queued));

        return Command::SUCCESS;
    }
}
