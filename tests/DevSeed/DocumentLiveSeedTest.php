<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Review\Command\ListDocumentsHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\Dev\DevDocumentSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DocumentLiveSeedTest extends KernelTestCase
{
    public function test_the_seed_writes_the_states_of_the_document_live_previews_once(): void
    {
        $this->seed();
        $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $archived = $em->find(Document::class, DevDocumentSeeder::documentId('live-archived'));
        self::assertInstanceOf(Document::class, $archived);
        self::assertNotNull($archived->archivedAt);

        $thread = $em->find(Document::class, DevDocumentSeeder::documentId('live-thread'));
        self::assertInstanceOf(Document::class, $thread);
        $comments = $em->getRepository(Comment::class)->findBy(['version' => $thread->currentVersion()]);
        self::assertCount(2, $comments);
        $roots = array_values(array_filter($comments, static fn (Comment $comment): bool => !$comment->parent instanceof Comment));
        self::assertCount(1, $roots);
        self::assertSame(CommentStatus::Addressed, $roots[0]->status);

        $lifecycle = $em->find(Project::class, DevProjectSeeder::lifecycleProjectId()) ?? throw new \LogicException('The lifecycle project is missing.');
        $total = \count(self::getContainer()->get(DocumentRepository::class)->findPaginatedByProject($lifecycle, 2, ListDocumentsHandler::PER_PAGE));
        self::assertGreaterThan(ListDocumentsHandler::PER_PAGE, $total);
    }

    private function seed(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:dev:seed'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
