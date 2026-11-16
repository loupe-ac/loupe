<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\EventListener;

use App\Mercure\LiveUpdateTransactionBuffer;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class QueueProjectDeletedOnProjectDeletingTest extends KernelTestCase
{
    public function test_a_committed_deletion_releases_the_project_deleted_signal(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Deleting Owner', email: 'deleting-owner@example.com');
        $project = new Project($owner, 'Doomed project');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id ?? throw new \LogicException('The project has no id.');

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        $buffer = self::getContainer()->get(LiveUpdateTransactionBuffer::class);
        self::assertInstanceOf(LiveUpdateTransactionBuffer::class, $buffer);
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $signals = array_values(array_filter(
            $buffer->takeCommitted(),
            static fn (array $signal): bool => str_contains($signal['data'], 'project.deleted'),
        ));
        self::assertCount(1, $signals);
        self::assertSame($topics->forWorkerRuns($projectId), $signals[0]['topic']);
        self::assertSame(
            ['type' => 'project.deleted', 'projectId' => (string) $projectId, 'origin' => null],
            json_decode($signals[0]['data'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
