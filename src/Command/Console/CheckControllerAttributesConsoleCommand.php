<?php

declare(strict_types=1);

namespace App\Command\Console;

use App\Routing\ControllerAttributeProbe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fails when an attribute of a routed controller cannot be created. The
 * production image build runs it, so a class that the image lacks stops the
 * build and not the first request. A router that finds no controller fails
 * too, because a check that finds nothing reports nothing.
 */
#[AsCommand(
    name: 'app:check-controller-attributes',
    description: 'Create every attribute of every routed controller and fail on an error.',
)]
final class CheckControllerAttributesConsoleCommand extends Command
{
    public function __construct(
        private readonly ControllerAttributeProbe $probe,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->probe->probe();

        $io->writeln(\sprintf(
            '%d routes, %d controllers, %d attributes.',
            $result['routes'],
            $result['controllers'],
            $result['attributes'],
        ));

        foreach ($result['failures'] as $failure) {
            $io->error($failure);
        }

        if (0 === $result['controllers']) {
            $io->error('The router found no controller.');

            return Command::FAILURE;
        }

        return [] === $result['failures'] ? Command::SUCCESS : Command::FAILURE;
    }
}
