<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowBinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowBinding> */
class WorkflowBindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowBinding::class);
    }

    public function findOneByProjectId(Uuid $projectId): ?WorkflowBinding
    {
        return $this->findOneBy(['project' => $projectId]);
    }

    /**
     * Reads the stored definition past the identity map, so a caller that holds a lock sees what another transaction committed.
     *
     * @return array<mixed>|null
     */
    public function findDefinitionFresh(Uuid $projectId): ?array
    {
        $definition = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT definition FROM workflow_bindings WHERE project_id = :projectId',
            ['projectId' => $projectId->toRfc4122()],
        );
        if (!\is_string($definition)) {
            return null;
        }
        $decoded = json_decode($definition, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The skill packages and the profile need that the stored copy of each project names, in one read. A copy from before the fields has null for both.
     *
     * @param list<Uuid> $projectIds
     *
     * @return array<string, array{skillPackages: ?list<string>, needsProfile: ?bool}> by RFC 4122 project id
     */
    public function findSkillSettings(array $projectIds): array
    {
        if ([] === $projectIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT project_id, definition->\'skillPackages\' AS skill_packages, definition->\'needsProfile\' AS needs_profile
             FROM workflow_bindings
             WHERE project_id IN (:projectIds)',
            ['projectIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $projectIds)],
            ['projectIds' => ArrayParameterType::STRING],
        );
        $settings = [];
        foreach ($rows as $row) {
            $packages = \is_string($row['skill_packages']) ? json_decode($row['skill_packages'], true, 512, \JSON_THROW_ON_ERROR) : null;
            $needsProfile = \is_string($row['needs_profile']) ? json_decode($row['needs_profile'], true, 512, \JSON_THROW_ON_ERROR) : null;
            $settings[Uuid::fromString((string) $row['project_id'])->toRfc4122()] = [
                'skillPackages' => \is_array($packages) && array_is_list($packages) && array_all($packages, static fn (mixed $name): bool => \is_string($name)) ? $packages : null,
                'needsProfile' => \is_bool($needsProfile) ? $needsProfile : null,
            ];
        }

        return $settings;
    }

    /**
     * The cards of every bound project that sit in a column that is not terminal. A held card counts, because its markers still show.
     *
     * @return list<string> RFC 4122 card ids
     */
    public function findOpenBoundCardIds(): array
    {
        return array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT c.id FROM board_cards c
             JOIN board_columns col ON col.id = c.column_id
             JOIN workflow_bindings b ON b.project_id = c.project_id
             WHERE col.terminal = false
             ORDER BY c.id',
        ));
    }
}
