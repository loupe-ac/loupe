<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Verdict;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubmitReviewDecisionGateTest extends KernelTestCase
{
    private const string MARKDOWN = "<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->\n\n<!-- decision: region -->\n\n- ( ) Europe\n- ( ) America\n\n<!-- /decision -->";

    public function test_an_approval_with_unanswered_decisions_is_refused_and_the_document_stays_in_review(): void
    {
        [$reviewer, $document] = $this->seed();

        try {
            $this->submit($reviewer, $document, Verdict::Approved);
            self::fail('An approval with unanswered decisions must be refused.');
        } catch (DomainErrors $error) {
            self::assertSame(['verdict' => 'review.document.flash.decisions_unanswered'], $error->errors);
            self::assertSame(['verdict' => ['%count%' => 2]], $error->parameters);
        }

        $this->em()->clear();
        self::assertSame(DocumentStatus::InReview, $this->em()->find(Document::class, $document->id)?->status);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM reviews'));
    }

    public function test_the_refusal_counts_only_the_decisions_with_no_answer(): void
    {
        [$reviewer, $document] = $this->seed();
        $this->answer($reviewer, $document, 'target', [1], null);

        try {
            $this->submit($reviewer, $document, Verdict::Approved);
            self::fail('One decision still has no answer.');
        } catch (DomainErrors $error) {
            self::assertSame(['verdict' => ['%count%' => 1]], $error->parameters);
        }
    }

    public function test_an_approval_goes_through_when_every_decision_has_an_answer_or_a_note(): void
    {
        [$reviewer, $document] = $this->seed();
        $this->answer($reviewer, $document, 'target', [1], null);
        $this->answer($reviewer, $document, 'region', [], 'Either works.');

        $review = $this->submit($reviewer, $document, Verdict::Approved);

        self::assertSame(Verdict::Approved, $review->verdict);
        self::assertSame(DocumentStatus::Approved, $document->status);
    }

    public function test_a_request_for_changes_is_allowed_while_decisions_have_no_answer(): void
    {
        [$reviewer, $document] = $this->seed();

        $review = $this->submit($reviewer, $document, Verdict::ChangesRequested, 'Answer the decisions first.');

        self::assertSame(Verdict::ChangesRequested, $review->verdict);
    }

    /** @return array{User, Document} */
    private function seed(): array
    {
        self::bootKernel();
        $reviewer = new User(fullName: 'Reviewer', email: 'gate-'.uniqid().'@example.com', password: 'hashed-placeholder');
        $this->em()->persist($reviewer);
        $project = new Project($reviewer, 'p-'.uniqid());
        $this->em()->persist($project);
        $this->em()->flush();

        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$reviewer, $create(new CreateDocumentCommand($project, 'Deploy plan', self::MARKDOWN))];
    }

    /** @param list<int> $indexes */
    private function answer(User $reviewer, Document $document, string $decisionId, array $indexes, ?string $note): void
    {
        $save = self::getContainer()->get(SaveDecisionAnswerHandler::class);
        self::assertInstanceOf(SaveDecisionAnswerHandler::class, $save);
        $save(new SaveDecisionAnswerCommand($document, $decisionId, 1, $indexes, $note, false, $reviewer));
    }

    private function submit(User $reviewer, Document $document, Verdict $verdict, ?string $note = null): \App\Module\Review\Entity\Review
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        return $handler(new SubmitReviewCommand($reviewer, $document, $verdict->value, 1, $note));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
