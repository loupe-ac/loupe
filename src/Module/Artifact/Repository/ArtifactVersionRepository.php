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

    /**
     * The highest version number of each artifact, in one query.
     *
     * @param list<Artifact> $artifacts
     *
     * @return array<string, int> keyed by artifact id
     */
    public function latestNumbers(array $artifacts): array
    {
        if ([] === $artifacts) {
            return [];
        }

        /** @var list<array{id: mixed, latest: mixed}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.artifact) AS id, MAX(v.number) AS latest')
            ->andWhere('v.artifact IN (:artifacts)')
            ->setParameter('artifacts', $artifacts)
            ->groupBy('v.artifact')
            ->getQuery()
            ->getArrayResult();

        $latest = [];
        foreach ($rows as $row) {
            $latest[(string) $row['id']] = (int) $row['latest'];
        }

        return $latest;
    }

    /**
     * Every version of the artifact with its file count and total size, oldest first.
     *
     * @return list<array{version: ArtifactVersion, fileCount: int, totalBytes: int}>
     */
    public function summaries(Artifact $artifact): array
    {
        /** @var list<array{0: ArtifactVersion, fileCount: mixed, totalBytes: mixed}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v, COUNT(f.id) AS fileCount, COALESCE(SUM(b.size), 0) AS totalBytes')
            ->leftJoin('v.files', 'f')
            ->leftJoin('f.blob', 'b')
            ->andWhere('v.artifact = :artifact')
            ->setParameter('artifact', $artifact)
            ->groupBy('v.id')
            ->orderBy('v.number', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'version' => $row[0],
                'fileCount' => (int) $row['fileCount'],
                'totalBytes' => (int) $row['totalBytes'],
            ],
            $rows,
        );
    }
}
