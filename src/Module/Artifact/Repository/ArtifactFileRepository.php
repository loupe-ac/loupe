<?php

declare(strict_types=1);

namespace App\Module\Artifact\Repository;

use App\Module\Artifact\Entity\ArtifactFile;
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
}
