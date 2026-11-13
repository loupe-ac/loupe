<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Project\Contract\Event\ProjectSetUp;
use App\Module\Project\Contract\ProjectMilestones;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsAlias(ProjectMilestones::class)]
final readonly class DatabaseProjectMilestones implements ProjectMilestones
{
    private const array REQUIRED = [self::INIT_FINISHED, self::BRIDGE_CONNECTED];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    #[\Override]
    public function reach(Uuid $projectId, string $key): void
    {
        $turnedOn = $this->em->wrapInTransaction(function () use ($projectId, $key): bool {
            // The lock makes two writers take turns, so the second check sees the first insert.
            if (!$this->projects->lockIdForWrite($projectId)) {
                return false;
            }

            $connection = $this->em->getConnection();
            $connection->executeStatement(
                'INSERT INTO project_milestones (id, project_id, "key", reached_at) VALUES (:id, :project, :key, :at) ON CONFLICT (project_id, "key") DO NOTHING',
                ['id' => Uuid::v7(), 'project' => $projectId, 'key' => $key, 'at' => $this->clock->now()],
                ['id' => UuidType::NAME, 'project' => UuidType::NAME, 'key' => Types::STRING, 'at' => Types::DATETIME_IMMUTABLE],
            );

            return 1 === $connection->executeStatement(
                'UPDATE projects SET set_up = true WHERE id = :project AND NOT set_up'
                .' AND (SELECT count(*) FROM project_milestones WHERE project_id = :project AND "key" IN (:keys)) = :required',
                ['project' => $projectId, 'keys' => self::REQUIRED, 'required' => \count(self::REQUIRED)],
                ['project' => UuidType::NAME, 'keys' => ArrayParameterType::STRING, 'required' => Types::INTEGER],
            );
        });

        if ($turnedOn) {
            // The update bypasses the unit of work, so a project loaded earlier still reads the old flag.
            $loaded = $this->em->getUnitOfWork()->tryGetById($projectId, Project::class);
            if ($loaded instanceof Project) {
                $loaded->setUp = true;
            }
            $this->events->dispatch(new ProjectSetUp($projectId));
        }
    }
}
