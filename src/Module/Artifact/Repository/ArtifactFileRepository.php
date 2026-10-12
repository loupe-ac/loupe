<?php

declare(strict_types=1);

namespace App\Module\Artifact\Repository;

use App\Module\Artifact\Entity\ArtifactFile;
use App\Module\Artifact\Entity\ArtifactVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ArtifactFile> */
class ArtifactFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactFile::class);
    }

    /** An exact match on the stored path, so no path can reach outside the version. */
    public function findOneByVersionIdAndPath(string $versionId, string $path): ?ArtifactFile
    {
        if (!Uuid::isValid($versionId)) {
            return null;
        }

        return $this->createQueryBuilder('file')
            ->addSelect('blob')
            ->join('file.blob', 'blob')
            ->where('file.version = :version')
            ->andWhere('file.path = :path')
            ->setParameter('version', Uuid::fromString($versionId), UuidType::NAME)
            ->setParameter('path', $path)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<ArtifactVersion> $versions
     *
     * @return array<string, int> keyed by version id; a version with no file is absent
     */
    public function countByVersions(array $versions): array
    {
        if ([] === $versions) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('file')
            ->select('IDENTITY(file.version) AS id, COUNT(file.id) AS total')
            ->where('file.version IN (:versions)')
            ->setParameter('versions', $versions)
            ->groupBy('file.version')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
