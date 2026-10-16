<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command\Console;

use App\Module\Inbox\Command\ReconcileBridgeOutagesCommand;
use App\Module\Inbox\Command\ReconcileBridgeOutagesHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Opens and closes the bridge outage notice of each project, once. The scheduler
 * runs the same work every minute through the worker. This is the manual backstop.
 */
#[AsCommand(
    name: 'app:reconcile-bridge-outages',
    description: 'Open or close the notice that says no bridge can take the work of a project.',
)]
final class ReconcileBridgeOutagesConsoleCommand extends Command
{
    public function __construct(
        private readonly ReconcileBridgeOutagesHandler $reconcileBridgeOutages,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reconciled = ($this->reconcileBridgeOutages)(new ReconcileBridgeOutagesCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Reconciled %d project(s).', $reconciled));

        return Command::SUCCESS;
    }
}
