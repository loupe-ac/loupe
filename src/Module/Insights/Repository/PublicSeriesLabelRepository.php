<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\PublicSeriesLabel;
use App\Module\Insights\Entity\PublicSeriesLabelKind;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PublicSeriesLabel> */
class PublicSeriesLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicSeriesLabel::class);
    }

    /** @return array<string, int> the numbers of the project for one kind, by key */
    public function numbersFor(Project $project, PublicSeriesLabelKind $kind): array
    {
        $numbers = [];
        foreach ($this->findBy(['project' => $project, 'kind' => $kind]) as $label) {
            $numbers[$label->key] = $label->number;
        }

        return $numbers;
    }

    /** Stores a number for a key that has none. A key that has a number keeps it. Call it with the project locked. */
    public function insertIfAbsent(Project $project, PublicSeriesLabelKind $kind, string $key, int $number): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO insights_public_series_labels (id, project_id, kind, key, number) VALUES (:id, :project, :kind, :key, :number) ON CONFLICT (project_id, kind, key) DO NOTHING',
            ['id' => Uuid::v7()->toRfc4122(), 'project' => $project->requireId()->toRfc4122(), 'kind' => $kind->value, 'key' => $key, 'number' => $number],
        );
    }
}
