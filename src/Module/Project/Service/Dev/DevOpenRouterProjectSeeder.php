<?php

declare(strict_types=1);

namespace App\Module\Project\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * A second project of the dev user that holds the OpenRouter key and the
 * OpenRouter runs. "Dev Project" keeps no key, so a real OpenRouter run there
 * never reads its cost with the fake key.
 */
#[When('dev')]
#[When('test')]
final readonly class DevOpenRouterProjectSeeder implements DevSeederInterface
{
    public const string KEY = 'openrouter-project';
    public const string NAME = 'OpenRouter Project';

    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function projectId(): Uuid
    {
        return DevSeedId::of('project/project/openrouter');
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
        if ($this->projects->find(self::projectId()) instanceof Project) {
            return;
        }

        $project = new Project($context->devUser(), self::NAME, createdAt: $context->at(60));
        $context->ids->assign($project, self::projectId());
        $this->em->persist($project);
        $this->events->dispatch(new ProjectCreating($project, 'simple'));
        $this->em->flush();
    }
}
