<?php

declare(strict_types=1);

namespace App\Module\Project\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\Dev\DevAccountSeeder;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Gives one account more projects than a page of the projects list holds, so page 2 shows rows. The
 * installer-empty account owns none, so its list is the empty twin.
 */
#[When('dev')]
#[When('test')]
final readonly class DevProjectListSeeder implements DevSeederInterface
{
    public const string KEY = 'project-list';

    public const int COUNT = 24;

    public function __construct(
        private ProjectRepository $projects,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function projectId(int $number): Uuid
    {
        return DevSeedId::of('project/project/many-'.$number);
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
        $owner = $this->users->find(DevSeedId::of(DevAccountSeeder::PROJECTS_MANY_USER))
            ?? throw new \LogicException('The projects-many account does not exist.');

        for ($number = 1; $number <= self::COUNT; ++$number) {
            $this->project($context, $owner, $number);
        }
    }

    private function project(SeedContext $context, User $owner, int $number): void
    {
        $id = self::projectId($number);
        if ($this->projects->find($id) instanceof Project) {
            return;
        }

        $project = new Project($owner, 'Many '.$number, createdAt: $context->at($number * 5));
        $project->setUp = true;
        $context->ids->assign($project, $id);
        $this->em->persist($project);
        $this->events->dispatch(new ProjectCreating($project, 'simple'));
        $this->em->flush();
    }
}
