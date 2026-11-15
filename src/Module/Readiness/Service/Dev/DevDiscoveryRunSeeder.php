<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Starts a discovery run in the Lifecycle project, so its readiness settings show a running discovery. "Dev
 * Project" has no run, so its row shows the state with no discovery.
 */
#[When('dev')]
#[When('test')]
final readonly class DevDiscoveryRunSeeder implements DevSeederInterface
{
    public const string KEY = 'readiness-discovery-run';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private CardRepository $cards,
        private DiscoveryRunRepository $discoveryRuns,
    ) {
    }

    public static function runId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/lifecycle');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        if ($this->discoveryRuns->find(self::runId()) instanceof DiscoveryRun) {
            return;
        }

        $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
            ?? throw new \LogicException('The lifecycle project does not exist.');
        $column = $this->boardColumns->findForProject($project)[0]
            ?? throw new \LogicException('The lifecycle project has no column.');
        $card = new Card(
            project: $project,
            column: $column,
            title: 'Discover what '.$project->name.' needs for agents',
            body: 'This card tracks a read-only discovery run.',
            number: $this->cards->nextNumber($project),
            type: 'feature',
            createdAt: $context->at(120),
        );
        $context->ids->assign($card, DevSeedId::of('readiness/discovery-run/card'));
        $this->em->persist($card);

        $run = new DiscoveryRun($project, $card, $context->at(120));
        $context->ids->assign($run, self::runId());
        $this->em->persist($run);
        $this->em->flush();
    }
}
