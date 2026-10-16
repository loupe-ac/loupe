<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Entity\DocumentReviewAnswer;
use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\AgentReview\Workflow\DocumentReviewFactProvider;
use App\Module\AgentReview\Workflow\DocumentReviewFacts;
use App\Module\AgentReview\Workflow\DocumentReviewState;
use App\Module\Board\Entity\Card;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentReviewFactProviderTest extends KernelTestCase
{
    use DocumentReviewScenario;

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
        self::assertTrue($this->provider()->isOn());
        self::assertSame(DocumentReviewFacts::class, $this->provider()->factsClass());
        self::assertSame('workflow.source.agent_review', $this->provider()->source());
    }

    public function test_a_tagged_draft_with_no_review_has_an_empty_loop(): void
    {
        [$card, $document] = $this->cardWithDesign();

        $state = $this->only($card);

        self::assertSame((string) $document->id, $state->documentId);
        self::assertSame(['tech-design'], $state->tags);
        self::assertSame(1, $state->versionNumber);
        self::assertNull($state->reviewId);
        self::assertNull($state->conclusion);
        self::assertSame(0, $state->failedCount);
    }

    public function test_the_latest_review_failed_count_and_version_are_read(): void
    {
        [$card, $document] = $this->cardWithDesign(versions: 2);
        $this->documentReview($card->project, $card, $document, findings: 1, version: 1);
        $latest = $this->documentReview($card->project, $card, $document, findings: 1, version: 2);

        $state = $this->only($card);

        self::assertSame(2, $state->versionNumber);
        self::assertSame((string) $latest->id, $state->reviewId);
        self::assertSame(2, $state->reviewVersion);
        self::assertSame(AgentReviewConclusion::Failure, $state->conclusion);
        self::assertFalse($state->answered);
        self::assertFalse($state->needsPerson);
        self::assertSame(2, $state->failedCount);
    }

    public function test_an_answered_review_and_a_question_for_a_person_show(): void
    {
        [$card, $document] = $this->cardWithDesign();
        $review = new DocumentAgentReview($card->project, $card, $document, 1, 'Passed.', AgentReviewConclusion::Success, [
            new AgentReviewFinding(null, null, null, AgentReviewSeverity::Important, 'Ask', 'Body', AgentReviewCategory::Spec, true),
        ]);
        $this->em->persist($review);
        $this->em->flush();

        $before = $this->only($card);
        self::assertTrue($before->needsPerson);
        self::assertFalse($before->answered);
        self::assertSame(0, $before->failedCount);

        $review->answer([new DocumentReviewFindingAnswer(0, DocumentReviewAnswer::ToOwner)]);
        $this->em->flush();

        $after = $this->only($card);
        self::assertTrue($after->answered);
        self::assertTrue($after->revised);
    }

    public function test_an_answer_of_not_changed_alone_says_no_revision(): void
    {
        [$card, $document] = $this->cardWithDesign();
        $review = $this->documentReview($card->project, $card, $document, findings: 1);
        self::assertFalse($this->only($card)->revised);

        $review->answer([new DocumentReviewFindingAnswer(0, DocumentReviewAnswer::NotChanged, 'The design is right.')]);
        $this->em->flush();

        $state = $this->only($card);
        self::assertTrue($state->answered);
        self::assertFalse($state->revised);
    }

    public function test_documents_that_are_not_tagged_drafts_are_left_out(): void
    {
        [$card] = $this->cardWithDesign(status: DocumentStatus::InReview);
        $this->linkedDocument($card);

        self::assertSame([], $this->build($card)->documents);
    }

    public function test_the_fingerprint_changes_on_a_new_review_a_new_answer_and_a_new_version(): void
    {
        [$card, $document] = $this->cardWithDesign();
        $empty = $this->fingerprint($card);

        $review = $this->documentReview($card->project, $card, $document, findings: 1);
        $reviewed = $this->fingerprint($card);
        self::assertNotEquals($empty, $reviewed);
        self::assertEquals($reviewed, $this->fingerprint($card));

        $review->answer([new DocumentReviewFindingAnswer(0, DocumentReviewAnswer::Fixed)]);
        $this->em->flush();
        $answered = $this->fingerprint($card);
        self::assertNotEquals($reviewed, $answered);

        $document->addVersion('Text 2', '<p>Text 2</p>');
        $this->em->flush();
        $revised = $this->fingerprint($card);
        self::assertNotEquals($answered, $revised);

        $this->documentReview($card->project, $card, $document, findings: 1, version: 2);
        self::assertNotEquals($revised, $this->fingerprint($card));
    }

    /** @return array{Card, Document} */
    private function cardWithDesign(DocumentStatus $status = DocumentStatus::Draft, int $versions = 1): array
    {
        $project = $this->makeProject('design-review-facts');
        $card = $this->card($project);
        $this->em->flush();
        $document = $this->linkedDocument($card, $status, $versions);
        $tag = new Tag($project, 'tech-design');
        $this->em->persist($tag);
        $document->tags->add($tag);
        $this->em->flush();

        return [$card, $document];
    }

    private function only(Card $card): DocumentReviewState
    {
        $documents = $this->build($card)->documents;
        self::assertCount(1, $documents);

        return $documents[0];
    }

    private function build(Card $card): DocumentReviewFacts
    {
        $this->em->flush();
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(DocumentReviewFacts::class, $facts);

        return $facts;
    }

    private function fingerprint(Card $card): mixed
    {
        return $this->provider()->fingerprint($this->build($card));
    }

    private function provider(): DocumentReviewFactProvider
    {
        $provider = self::getContainer()->get(DocumentReviewFactProvider::class);
        self::assertInstanceOf(DocumentReviewFactProvider::class, $provider);

        return $provider;
    }
}
