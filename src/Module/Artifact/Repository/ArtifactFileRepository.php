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
     * The path, type and size of every file of the versions, in path order, in one query.
     * The bytes of a blob are never read.
     *
     * @param list<ArtifactVersion> $versions
     *
     * @return array<string, list<array{path: string, contentType: string, size: int}>> keyed by version id; a version with no file is absent
     */
    public function describeByVersions(array $versions): array
    {
        if ([] === $versions) {
            return [];
        }

        /** @var list<array{id: mixed, path: string, contentType: string, size: mixed}> $rows */
        $rows = $this->createQueryBuilder('file')
            ->select('IDENTITY(file.version) AS id, file.path AS path, file.contentType AS contentType, blob.size AS size')
            ->join('file.blob', 'blob')
            ->where('file.version IN (:versions)')
            ->setParameter('versions', $versions)
            ->orderBy('file.path', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $files = [];
        foreach ($rows as $row) {
            $files[(string) $row['id']][] = ['path' => $row['path'], 'contentType' => $row['contentType'], 'size' => (int) $row['size']];
        }

        return $files;
    }

    /**
     * The file count and the total size of each version, in one query.
     *
     * @param list<ArtifactVersion> $versions
     *
     * @return array<string, array{fileCount: int, totalBytes: int}> keyed by version id; a version with no file is absent
     */
    public function totalsByVersions(array $versions): array
    {
        if ([] === $versions) {
            return [];
        }

        /** @var list<array{id: mixed, fileCount: mixed, totalBytes: mixed}> $rows */
        $rows = $this->createQueryBuilder('file')
            ->select('IDENTITY(file.version) AS id, COUNT(file.id) AS fileCount, COALESCE(SUM(blob.size), 0) AS totalBytes')
            ->join('file.blob', 'blob')
            ->where('file.version IN (:versions)')
            ->setParameter('versions', $versions)
            ->groupBy('file.version')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['id']] = ['fileCount' => (int) $row['fileCount'], 'totalBytes' => (int) $row['totalBytes']];
        }

        return $totals;
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
