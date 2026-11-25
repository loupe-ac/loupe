<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command\Console;

use App\Module\Readiness\Command\AdoptLegacyDiscoveryRunsCommand;
use App\Module\Readiness\Command\AdoptLegacyDiscoveryRunsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Adopts the discovery runs that still hold a card, once. The scheduler runs the same work every minute. */
#[AsCommand(
    name: 'app:adopt-legacy-discovery-runs',
    description: 'Give each live discovery run that still holds a card its work request about the run.',
)]
final class AdoptLegacyDiscoveryRunsConsoleCommand extends Command
{
    public function __construct(
        private readonly AdoptLegacyDiscoveryRunsHandler $adoptLegacyDiscoveryRuns,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = ($this->adoptLegacyDiscoveryRuns)(new AdoptLegacyDiscoveryRunsCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Changed %d discovery run(s).', $changed));

        return Command::SUCCESS;
    }
}
