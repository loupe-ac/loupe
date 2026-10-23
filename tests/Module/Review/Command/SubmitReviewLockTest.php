<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * VerdictLockOrderTest proves what each lock mode does between two sessions. This test proves
 * that the handler takes the mode that test relies on.
 */
final class SubmitReviewLockTest extends KernelTestCase
{
    public function test_a_verdict_locks_the_project_row_in_the_verdict_mode_and_never_in_for_update(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Owner', email: 'lock-'.uniqid().'@example.com', password: 'hashed-placeholder');
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Deploy plan', '# Plain'));
        $document->status = DocumentStatus::InReview;
        $em->flush();
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $handler(new SubmitReviewCommand(reviewer: $owner, document: $document, verdict: 'approved', versionNumber: 1, note: null, expectedReviewId: null));

        $statements = [];
        foreach ($holder->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }
        $locks = array_values(array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/\bFOR (UPDATE|NO KEY UPDATE|SHARE|KEY SHARE)\b/', $sql)));
        self::assertNotEmpty($locks, 'The verdict ran and took row locks.');
        self::assertSame(
            ['SELECT id FROM projects WHERE id = ? '.DocumentRepository::VERDICT_LOCK, 'SELECT id FROM documents WHERE id = ? '.DocumentRepository::VERDICT_LOCK],
            \array_slice($locks, 0, 2),
            'The project row comes first, then the document row, both in the verdict mode.',
        );
        self::assertSame([], array_values(array_filter($locks, static fn (string $sql): bool => 1 === preg_match('/\bFOR UPDATE\b/', $sql) && str_contains($sql, 'projects'))));
    }
}
