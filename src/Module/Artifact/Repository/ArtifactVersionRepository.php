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

    /**
     * The newest version of each artifact, with whoever stored it, in one query.
     *
     * @param list<Artifact> $artifacts
     *
     * @return array<string, ArtifactVersion> keyed by artifact id
     */
    public function findLatestForArtifacts(array $artifacts): array
    {
        if ([] === $artifacts) {
            return [];
        }

        /** @var list<ArtifactVersion> $versions */
        $versions = $this->createQueryBuilder('version')
            ->addSelect('storedBy')
            ->leftJoin('version.storedBy', 'storedBy')
            ->where('version.artifact IN (:artifacts)')
            ->andWhere('version.number = (SELECT MAX(newer.number) FROM '.ArtifactVersion::class.' newer WHERE newer.artifact = version.artifact)')
            ->setParameter('artifacts', $artifacts)
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($versions as $version) {
            $latest[(string) $version->artifact->id] = $version;
        }

        return $latest;
    }
}
