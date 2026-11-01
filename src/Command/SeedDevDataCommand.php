<?php

declare(strict_types=1);

namespace App\Command;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedIds;
use App\DevSeed\SeedContext;
use App\DevSeed\SeederOrder;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Seeds a development database with the demo dataset. Each module owns its
 * seeders, and the command runs them in dependency order. Every run is safe to
 * repeat, because bootstrap runs it on every worktree entry.
 */
#[AsCommand(
    name: 'app:dev:seed',
    description: 'Seed a dev database with the demo dataset.',
)]
#[When('dev')]
#[When('test')]
final class SeedDevDataCommand extends Command
{
    /** @param iterable<DevSeederInterface> $seeders */
    public function __construct(
        #[AutowireIterator('app.dev_seeder')]
        private readonly iterable $seeders,
        private readonly ClockInterface $clock,
        private readonly DevSeedIds $ids,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('keep-flag-defaults', null, InputOption::VALUE_NONE, 'Leave the feature flags at their coded defaults.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = new SeedContext($this->clock->now(), $this->ids, $this->users, (bool) $input->getOption('keep-flag-defaults'));

        foreach (SeederOrder::sort($this->seeders) as $seeder) {
            $start = hrtime(true);
            $seeder->seed($context);
            $left = $this->ids->clearQueue();
            if ([] !== $left) {
                throw new \LogicException(\sprintf('The seeder "%s" queued a fixed id that no new %s used.', $seeder->key(), implode(', ', $left)));
            }
            $output->writeln(\sprintf('%s %.1f s', $seeder->key(), (hrtime(true) - $start) / 1e9));
        }

        // Printed for a human to copy into SITE_REVIEW_WIDGET_PROJECT.
        $project = $this->projects->find(DevProjectSeeder::devProjectId());
        if (null !== $project) {
            $output->writeln('SITE_REVIEW_WIDGET_PROJECT='.$project->id);
        }

        $io->success(\sprintf(
            'Seeded %s and %s (password: %s).',
            SeedContext::DEV_EMAIL,
            SeedContext::ADMIN_EMAIL,
            'password',
        ));

        return Command::SUCCESS;
    }
}
