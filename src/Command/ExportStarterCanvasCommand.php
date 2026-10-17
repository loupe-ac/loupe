<?php

declare(strict_types=1);

namespace App\Command;

use App\DesignStarter\StarterExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Writes the Claude Design starter canvas into a directory and prints each
 * path it wrote, relative to that directory, one per line.
 */
#[AsCommand(
    name: 'app:design-system:export-starter',
    description: 'Write the Claude Design starter canvas into a directory.',
)]
#[When('dev')]
#[When('test')]
final class ExportStarterCanvasCommand extends Command
{
    public function __construct(
        private readonly StarterExporter $exporter,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('dir', InputArgument::REQUIRED, 'The directory to write into');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getArgument('dir');
        if (!\is_string($directory) || '' === $directory) {
            throw new \InvalidArgumentException('The dir argument must be a non-empty path.');
        }

        foreach (($this->exporter)($directory) as $path) {
            $output->writeln($path, OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }
}
