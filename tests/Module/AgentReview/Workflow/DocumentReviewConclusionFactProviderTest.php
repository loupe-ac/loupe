<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Workflow\DocumentReviewConclusionFactProvider;
use App\Module\AgentReview\Workflow\DocumentReviewConclusionFacts;
use App\Module\Board\Entity\Card;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentReviewConclusionFactProviderTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_provider_is_on_and_gives_the_agent_review_source(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->isOn());
        self::assertSame(DocumentReviewConclusionFacts::class, $provider->factsClass());
        self::assertSame('workflow.source.agent_review', $provider->source());
    }

    public function test_each_document_gives_the_conclusion_of_its_newest_review_whatever_its_status(): void
    {
        $project = $this->workflowProject('conclusion-facts');
        $card = $this->card($project);
        $failedThenPassed = $this->linkedDocument($card, DocumentStatus::InReview);
        $passedThenFailed = $this->linkedDocument($card, DocumentStatus::Approved);
        $unreviewed = $this->linkedDocument($card, DocumentStatus::Draft);
        $this->reviewAt($card, $failedThenPassed, AgentReviewConclusion::Failure, '2026-10-22 09:00:00');
        $this->reviewAt($card, $failedThenPassed, AgentReviewConclusion::Success, '2026-10-22 10:00:00');
        $this->reviewAt($card, $passedThenFailed, AgentReviewConclusion::Success, '2026-10-22 09:00:00');
        $this->reviewAt($card, $passedThenFailed, AgentReviewConclusion::Failure, '2026-10-22 10:00:00');

        $facts = $this->build($card);

        self::assertSame([
            (string) $failedThenPassed->id => AgentReviewConclusion::Success,
            (string) $passedThenFailed->id => AgentReviewConclusion::Failure,
        ], $facts->newestByDocument);
        self::assertArrayNotHasKey((string) $unreviewed->id, $facts->newestByDocument);
    }

    public function test_a_review_for_another_card_does_not_count(): void
    {
        $project = $this->workflowProject('conclusion-facts-other');
        $card = $this->card($project);
        $other = $this->card($project, 2);
        $document = $this->linkedDocument($card, DocumentStatus::InReview);
        $this->reviewAt($other, $document, AgentReviewConclusion::Success, '2026-10-22 10:00:00');

        self::assertSame([], $this->build($card)->newestByDocument);
    }

    public function test_the_fingerprint_changes_with_a_new_conclusion(): void
    {
        $project = $this->workflowProject('conclusion-facts-print');
        $card = $this->card($project);
        $document = $this->linkedDocument($card, DocumentStatus::InReview);
        $empty = $this->provider()->fingerprint($this->build($card));
        $this->reviewAt($card, $document, AgentReviewConclusion::Failure, '2026-10-22 09:00:00');
        $failed = $this->provider()->fingerprint($this->build($card));
        $this->reviewAt($card, $document, AgentReviewConclusion::Success, '2026-10-22 10:00:00');
        $passed = $this->provider()->fingerprint($this->build($card));

        self::assertNotEquals($empty, $failed);
        self::assertNotEquals($failed, $passed);
        self::assertEquals($passed, $this->provider()->fingerprint($this->build($card)));
        json_encode($passed, \JSON_THROW_ON_ERROR);
    }

    private function reviewAt(Card $card, Document $document, AgentReviewConclusion $conclusion, string $at): void
    {
        $this->em->persist(new DocumentAgentReview($card->project, $card, $document, 1, 'Summary.', $conclusion, [], createdAt: new \DateTimeImmutable($at)));
        $this->em->flush();
    }

    private function build(Card $card): DocumentReviewConclusionFacts
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(DocumentReviewConclusionFacts::class, $facts);

        return $facts;
    }

    private function provider(): DocumentReviewConclusionFactProvider
    {
        $provider = self::getContainer()->get(DocumentReviewConclusionFactProvider::class);
        self::assertInstanceOf(DocumentReviewConclusionFactProvider::class, $provider);

        return $provider;
    }
}
