<?php

declare(strict_types=1);

namespace App\Module\Notification\Repository;

use App\Module\Notification\Entity\PushDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PushDevice> */
class PushDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushDevice::class);
    }
}
