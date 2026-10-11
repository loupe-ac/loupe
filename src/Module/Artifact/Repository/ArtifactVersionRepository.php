<?php

declare(strict_types=1);

namespace App\Module\Artifact\Repository;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Entity\ArtifactVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ArtifactVersion> */
class ArtifactVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactVersion::class);
    }

    /** The version with the highest number, without loading every version of the artifact. */
    public function findLatest(Artifact $artifact): ?ArtifactVersion
    {
        return $this->findOneBy(['artifact' => $artifact], ['number' => 'DESC']);
    }

    /** Scoped to the artifact, so a number cannot reach the version of another artifact. */
    public function findByNumber(Artifact $artifact, int $number): ?ArtifactVersion
    {
        return $this->findOneBy(['artifact' => $artifact, 'number' => $number]);
    }
}
