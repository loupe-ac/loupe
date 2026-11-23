<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command\Console;

use App\Module\Inbox\Command\CloseDefaultedInboxItemsCommand;
use App\Module\Inbox\Command\CloseDefaultedInboxItemsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Answers each open ask whose deadline passed with the recommended option, once. The scheduler runs the same work
 * every minute through the worker. This is the manual backstop.
 */
#[AsCommand(
    name: 'app:inbox:close-defaulted',
    description: 'Answer each open inbox ask whose deadline passed with the recommended option of the agent.',
)]
final class CloseDefaultedInboxItemsConsoleCommand extends Command
{
    public function __construct(
        private readonly CloseDefaultedInboxItemsHandler $closeDefaulted,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $answered = ($this->closeDefaulted)(new CloseDefaultedInboxItemsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Answered %d item(s).', $answered));

        return Command::SUCCESS;
    }
}
