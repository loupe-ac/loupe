<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command\Console;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\SeedGitIdentitiesCommand;
use App\Module\Bridge\Command\SeedGitIdentitiesHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'app:dev:seed-git-identities',
    description: 'Dev only: give a project five fake bridges that report a git identity, to preview the Git identity panel. Turn the setting on and off, set the Agent GitHub login or connect GitHub, then reload the Connect page. Safe to run again.',
)]
#[When('dev')]
final class SeedGitIdentitiesConsoleCommand extends Command
{
    public function __construct(
        private readonly SeedGitIdentitiesHandler $seedGitIdentities,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('projectId', InputArgument::REQUIRED, 'The id of the project');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $names = ($this->seedGitIdentities)(new SeedGitIdentitiesCommand((string) $input->getArgument('projectId')));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $field => $message) {
                $io->error($field.': '.$message);
            }

            return Command::FAILURE;
        }

        $io->success('Seeded '.implode(', ', $names).'.');

        return Command::SUCCESS;
    }
}
