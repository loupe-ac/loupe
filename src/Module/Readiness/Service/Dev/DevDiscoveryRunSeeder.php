<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
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

        $run = new DiscoveryRun($project, $context->at(120));
        $context->ids->assign($run, self::runId());
        $this->em->persist($run);
        $this->em->flush();
    }
}
