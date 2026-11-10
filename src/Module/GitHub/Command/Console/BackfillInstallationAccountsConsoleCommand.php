<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command\Console;

use App\Module\GitHub\Command\BackfillInstallationAccountsCommand;
use App\Module\GitHub\Command\BackfillInstallationAccountsHandler;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:github:backfill-installation-accounts',
    description: 'Fill the GitHub account id of each installation record that lacks one.',
)]
final class BackfillInstallationAccountsConsoleCommand extends Command
{
    public function __construct(
        private readonly BackfillInstallationAccountsHandler $backfill,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = ($this->backfill)(new BackfillInstallationAccountsCommand());
        } catch (GitHubAppApiFailed $e) {
            $io->error(\sprintf('GitHub refused the listing: %s.', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Filled %d installation(s). %d not listed by GitHub kept no account id.', $result->filled, $result->unlisted));

        return Command::SUCCESS;
    }
}
