<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service\Dev;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * A run, its fact and a work request copy the number of their card and outlive
 * it. A seeder that restores a deleted card gives it a new number, so it writes
 * that number to these rows and keeps the rows.
 */
#[When('dev')]
#[When('test')]
final readonly class DevRunCardLinks
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function relink(Project $project, Uuid $cardId, int $number): void
    {
        foreach ([WorkerRun::class, WorkerRunFact::class, WorkRequest::class] as $entity) {
            $this->em->createQueryBuilder()
                ->update($entity, 'e')
                ->set('e.cardNumber', ':number')
                ->andWhere('e.project = :project')
                ->andWhere('e.subjectType = :type')
                ->andWhere('e.subjectId = :card')
                ->andWhere('e.cardNumber <> :number')
                ->setParameter('number', $number)
                ->setParameter('project', $project)
                ->setParameter('type', WorkSubject::CARD)
                ->setParameter('card', $cardId, UuidType::NAME)
                ->getQuery()
                ->execute();
        }
    }
}
