<?php

declare(strict_types=1);

namespace App\Module\Landing\Command\Console;

use App\Module\Landing\Command\StampDocsCommand;
use App\Module\Landing\Command\StampDocsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:site:stamp-docs',
    description: 'Copy the built docs and put the site bar and footer of this instance in each page.',
)]
final class StampDocsConsoleCommand extends Command
{
    public function __construct(
        private readonly StampDocsHandler $stampDocs,

        #[Autowire('%kernel.project_dir%/var/tailwind/app.built.css')]
        private readonly string $stylesheet,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'The built docs', '/var/www/docs-build')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The folder that nginx serves', '/var/www/docs')
            ->addOption('stylesheet', null, InputOption::VALUE_REQUIRED, 'The compiled app stylesheet', $this->stylesheet);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = ($this->stampDocs)(new StampDocsCommand(
            source: (string) $input->getOption('source'),
            target: (string) $input->getOption('target'),
            stylesheet: (string) $input->getOption('stylesheet'),
        ));

        if ([] !== $result->problems) {
            $io->error($result->problems);

            return Command::FAILURE;
        }

        $io->success(sprintf('Stamped %d docs pages.', $result->pages));

        return Command::SUCCESS;
    }
}
