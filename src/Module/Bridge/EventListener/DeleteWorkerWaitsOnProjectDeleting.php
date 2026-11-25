<?php

declare(strict_types=1);

namespace App\Module\Bridge\EventListener;

use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Project\Event\ProjectDeleting;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Deletes the waits of a project that is going away. It runs inside ProjectDeleter's transaction. */
#[AsEventListener]
final readonly class DeleteWorkerWaitsOnProjectDeleting
{
    public function __construct(
        private WorkerWaitRepository $workerWaits,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        // A wait that opens now holds a key share on the project, and only FOR UPDATE waits for it.
        $this->em->lock($event->project, LockMode::PESSIMISTIC_WRITE);

        $this->workerWaits->deleteOfProject($event->project);
    }
}
