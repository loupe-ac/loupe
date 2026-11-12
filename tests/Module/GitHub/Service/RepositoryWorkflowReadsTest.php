<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\WorkflowFileReadQueues;
use App\Module\GitHub\Messenger\ReadRepositoryWorkflowSettings;
use App\Module\GitHub\Service\RepositoryWorkflowReads;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class RepositoryWorkflowReadsTest extends KernelTestCase
{
    public function test_it_queues_an_unforced_read_by_default_and_a_forced_one_on_request(): void
    {
        self::bootKernel();
        $project = new Project(new User(fullName: 'Riley', email: 'reads@example.com', password: 'x'), 'reads');
        $repository = new ForgeRepository($project, 'github', 'ext-1', 'acme/app', ForgeRepositorySource::Installation, '1');
        new \ReflectionProperty($repository, 'id')->setValue($repository, Uuid::v7());
        $reads = self::getContainer()->get(RepositoryWorkflowReads::class);
        self::assertInstanceOf(RepositoryWorkflowReads::class, $reads);
        self::assertTrue($reads->supports('github'));
        self::assertFalse($reads->supports('gitlab'));
        self::assertSame($reads, new WorkflowFileReadQueues([$reads])->for('github'));

        $reads->queue($repository);
        $reads->queue($repository, true);

        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertEquals(
            [new ReadRepositoryWorkflowSettings((string) $repository->id), new ReadRepositoryWorkflowSettings((string) $repository->id, true)],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
    }
}
