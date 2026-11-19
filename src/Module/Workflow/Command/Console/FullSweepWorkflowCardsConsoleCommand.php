<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command\Console;

use App\Module\Workflow\Command\FullSweepWorkflowCardsCommand;
use App\Module\Workflow\Command\FullSweepWorkflowCardsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** The scheduler starts the same work once the flag interval has passed. This is the manual backstop, and it runs at once. */
#[AsCommand(
    name: 'app:full-sweep-workflow-cards',
    description: 'Queue a check of every open card now, and report each change that the marks missed.',
)]
final class FullSweepWorkflowCardsConsoleCommand extends Command
{
    public function __construct(
        private readonly FullSweepWorkflowCardsHandler $fullSweep,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queued = ($this->fullSweep)(new FullSweepWorkflowCardsCommand(force: true));

        new SymfonyStyle($input, $output)->success(\sprintf('Queued %d evaluation(s).', $queued));

        return Command::SUCCESS;
    }
}
