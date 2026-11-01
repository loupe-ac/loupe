<?php

declare(strict_types=1);

namespace App\Module\Project\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

#[When('dev')]
#[When('test')]
final readonly class DevProjectSeeder implements DevSeederInterface
{
    public const string KEY = 'projects';
    public const string DEV_PROJECT_NAME = 'Dev Project';

    public function __construct(
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function devProjectId(): Uuid
    {
        return DevSeedId::of('project/project/dev-project');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevAccountSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $owner = $context->devUser();
        $id = self::devProjectId();
        if ($this->projects->find($id) instanceof Project) {
            return;
        }

        // The newest project comes first in the project picker.
        $project = new Project($owner, self::DEV_PROJECT_NAME, createdAt: $context->at(0));

        $this->renameOldProject($owner, self::DEV_PROJECT_NAME);

        $context->ids->assign($project, $id);
        $this->em->persist($project);
        $this->events->dispatch(new ProjectCreating($project, 'simple'));
        $this->em->flush();
    }

    /** Frees a name that the owner holds under another id, because a project name is unique per owner. */
    public function renameOldProject(User $owner, string $name): void
    {
        $old = $this->projects->findOneByOwnerAndName($owner, $name);
        if ($old instanceof Project) {
            $old->name = \sprintf('%s (old %s)', $name, substr((string) $old->id, 0, 8));
            $this->em->flush();
        }
    }
}
