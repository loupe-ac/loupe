<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DeleteWorkerWaitsOnProjectDeletingTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_deleting_a_project_deletes_its_waits_and_keeps_the_waits_of_another(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'wait-delete-'.uniqid().'@example.com');
        $doomed = $this->project($em, $owner, 'Doomed');
        $spared = $this->project($em, $owner, 'Spared');
        $this->seedWait($doomed);
        $kept = $this->seedWait($spared);
        $doomedId = $doomed->requireId()->toRfc4122();

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        $connection = $em->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = ?', [$doomedId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_waits WHERE project_id = ?', [$doomedId]));
        $em->clear();
        self::assertNotNull($em->find(WorkerWait::class, $kept->id));
    }

    private function seedWait(Project $project): WorkerWait
    {
        $opened = new \DateTimeImmutable('2026-09-23 10:00:00');
        $wait = new WorkerWait($project, Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v4(), [], $opened, $opened->modify('+1 hour'));
        $this->em()->persist($wait);
        $this->em()->flush();

        return $wait;
    }
}
