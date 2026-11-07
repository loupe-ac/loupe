<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowSettingOverride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowSettingOverride> */
class WorkflowSettingOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowSettingOverride::class);
    }

    /** @return list<WorkflowSettingOverride> */
    public function findByRepositoryId(Uuid $repositoryId): array
    {
        return array_values($this->findBy(['repositoryId' => $repositoryId], ['settingKey' => 'ASC']));
    }

    public function findOneByRepositoryIdAndKey(Uuid $repositoryId, string $key): ?WorkflowSettingOverride
    {
        return $this->findOneBy(['repositoryId' => $repositoryId, 'settingKey' => $key]);
    }

    /** @return list<WorkflowSettingOverride> the rows of one key in every repository of the project */
    public function findByProjectIdAndKey(Uuid $projectId, string $key): array
    {
        return array_values($this->createQueryBuilder('o')
            ->andWhere('IDENTITY(o.project) = :project')
            ->andWhere('o.settingKey = :key')
            ->setParameter('project', $projectId, UuidType::NAME)
            ->setParameter('key', $key)
            ->orderBy('o.repositoryId', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** The blob sha of the file the rows of the repository come from, or null when the repository holds no row. */
    public function findBlobShaByRepositoryId(Uuid $repositoryId): ?string
    {
        $blob = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT blob_sha FROM workflow_setting_overrides WHERE repository_id = :repositoryId AND blob_sha IS NOT NULL LIMIT 1',
            ['repositoryId' => $repositoryId->toRfc4122()],
        );

        return \is_string($blob) ? $blob : null;
    }
}
