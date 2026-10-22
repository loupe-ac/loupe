<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubmitReviewWorkflowRuleTest extends KernelTestCase
{
    private const string MARKDOWN = "<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->";

    public function test_a_verdict_of_a_rule_names_the_rule_and_the_workflow_user(): void
    {
        [$workflow, $document] = $this->seed('# Plain');

        $review = $this->submit($workflow, $document, 'approved', 1, null, 'tech-design-auto-approve');

        self::assertSame('tech-design-auto-approve', $review->workflowRule);
        self::assertSame($workflow, $review->reviewer);
        self::assertNull($review->note);
        self::assertSame(DocumentStatus::Approved, $document->status);
    }

    public function test_a_verdict_of_a_person_has_no_rule(): void
    {
        [, $document, $owner] = $this->seed('# Plain');

        $review = $this->submit($owner, $document, 'approved', 1, null, null);

        self::assertNull($review->workflowRule);
    }

    public function test_a_rule_that_read_an_older_version_is_refused_and_the_document_stays_in_review(): void
    {
        [$workflow, $document] = $this->seed('# Plain');
        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, '# Plain, revised', 'A new round.'));

        try {
            $this->submit($workflow, $document, 'approved', 1, null, 'tech-design-auto-approve');
            self::fail('The version the rule read is no longer the newest.');
        } catch (DomainErrors $error) {
            self::assertSame(['versionNumber' => 'review.document.flash.verdict_stale'], $error->errors);
        }

        $this->em()->clear();
        self::assertSame(DocumentStatus::InReview, $this->em()->find(Document::class, $document->id)?->status);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews'));
    }

    public function test_a_rule_that_read_an_older_review_is_refused(): void
    {
        [$workflow, $document, $owner] = $this->seed('# Plain');
        $this->submit($owner, $document, 'changes-requested', 1, 'Rework it.', null);

        try {
            $this->submit($workflow, $document, 'approved', 1, null, 'tech-design-auto-approve');
            self::fail('A verdict landed after the rule read its facts.');
        } catch (DomainErrors $error) {
            self::assertSame(['expectedReviewId' => 'review.document.flash.verdict_changed'], $error->errors);
        }
    }

    public function test_a_rule_cannot_approve_while_a_decision_has_no_answer(): void
    {
        [$workflow, $document] = $this->seed(self::MARKDOWN);

        try {
            $this->submit($workflow, $document, 'approved', 1, null, 'tech-design-auto-approve');
            self::fail('A decision has no answer.');
        } catch (DomainErrors $error) {
            self::assertSame(['verdict' => 'review.document.flash.decisions_unanswered'], $error->errors);
        }
    }

    private function submit(User $reviewer, Document $document, string $verdict, int $version, ?string $note, ?string $rule): \App\Module\Review\Entity\Review
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        return $handler(new SubmitReviewCommand(
            reviewer: $reviewer,
            document: $document,
            verdict: $verdict,
            versionNumber: $version,
            note: $note,
            expectedReviewId: null,
            workflowRule: $rule,
        ));
    }

    /** @return array{User, Document, User} the workflow user, the document and its owner */
    private function seed(string $markdown): array
    {
        self::bootKernel();
        $owner = new User(fullName: 'Owner', email: 'rule-'.uniqid().'@example.com', password: 'hashed-placeholder');
        $this->em()->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $this->em()->persist($project);
        $this->em()->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($project, 'Deploy plan', $markdown));
        $document->status = DocumentStatus::InReview;
        $this->em()->flush();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return [$users->workflow(), $document, $owner];
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
