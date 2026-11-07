<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Workflow\Entity\WorkflowRepositorySetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowRepositorySetting> */
class WorkflowRepositorySettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRepositorySetting::class);
    }

    public function findOneByRepositoryIdAndKey(Uuid $repositoryId, string $key): ?WorkflowRepositorySetting
    {
        return $this->findOneBy(['repositoryId' => $repositoryId, 'settingKey' => $key]);
    }
}
