<?php

declare(strict_types=1);

namespace App\Module\Insights\Repository;

use App\Module\Insights\Entity\PublicSeriesLabel;
use App\Module\Insights\Entity\PublicSeriesLabelKind;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
}
