<?php

declare(strict_types=1);

namespace App\Module\Artifact\Repository;

use App\Module\Artifact\Entity\ArtifactBlob;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<ArtifactBlob> */
class ArtifactBlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtifactBlob::class);
    }

    public function findOneByHash(Project $project, string $sha256): ?ArtifactBlob
    {
        return $this->findOneBy(['project' => $project, 'sha256' => $sha256]);
    }

    /**
     * Returns the blob of this content in the project and writes it when it is new.
     *
     * The insert runs in SQL with ON CONFLICT DO NOTHING, so two stores of the
     * same file at once end with one row and no unique violation.
     */
    public function findOrCreate(Project $project, string $contents): ArtifactBlob
    {
        $sha256 = hash('sha256', $contents);

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO artifact_blobs (id, project_id, sha256, bytes, size) VALUES (:id, :project, :sha256, :bytes, :size) ON CONFLICT (project_id, sha256) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => $project->id?->toRfc4122(),
                'sha256' => $sha256,
                'bytes' => $contents,
                'size' => \strlen($contents),
            ],
            ['bytes' => ParameterType::LARGE_OBJECT, 'size' => Types::INTEGER],
        );

        return $this->findOneByHash($project, $sha256) ?? throw new \LogicException('The blob was stored and cannot be read.');
    }
}
