<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Entity\DocumentAgentReview;
use App\Module\AgentReview\Entity\DocumentReviewAnswer;
use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowDocumentAgentReviewSummaryTest extends WebTestCase
{
    use DocumentReviewScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('agent-review-summary');
        $this->card = $this->card($this->project);
        $this->em->flush();
    }

    private function storeReview(Document $document, int $version, AgentReviewConclusion $conclusion, string $severity, ?DocumentReviewFindingAnswer $answer = null): DocumentAgentReview
    {
        $review = new DocumentAgentReview(
            project: $this->project,
            card: $this->card,
            document: $document,
            versionNumber: $version,
            summary: 'Summary.',
            conclusion: $conclusion,
            findings: [new AgentReviewFinding(null, null, null, AgentReviewSeverity::from($severity), 'R3 contradicts decision 4.', 'The two sections disagree.', AgentReviewCategory::Spec)],
        );
        if (null !== $answer) {
            $review->answer([$answer]);
        }
        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }

    private function visit(Document $document): void
    {
        $owner = $this->project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $this->em->flush();
        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/documents/'.$document->id.'/review');
        self::assertResponseIsSuccessful();
    }

    public function test_a_passed_review_shows_its_rounds_and_the_not_changed_label(): void
    {
        $document = $this->linkedDocument($this->card, DocumentStatus::InReview, versions: 2);
        $this->storeReview($document, 1, AgentReviewConclusion::Failure, 'important', new DocumentReviewFindingAnswer(0, DocumentReviewAnswer::NotChanged, 'Decision 6 was revised.'));
        $this->storeReview($document, 2, AgentReviewConclusion::Success, 'nit');

        $this->visit($document);

        self::assertSelectorTextContains('[data-testid="agent-review-summary"] .lp-status-chip', 'Agent review passed');
        self::assertSelectorTextContains('[data-testid="agent-review-summary"] .lp-agent-review__meta', '2 rounds · 1 not changed · 1 nit open');
        self::assertSelectorTextContains('[data-testid="agent-review-summary"]', 'Show rounds');
        self::assertSelectorTextContains('[data-testid="agent-review-summary"]', 'Not changed');
        self::assertSelectorTextContains('[data-testid="agent-review-summary"]', 'Decision 6 was revised.');
    }

    public function test_a_published_document_after_one_failed_round_is_skipped(): void
    {
        $document = $this->linkedDocument($this->card, DocumentStatus::InReview);
        $this->storeReview($document, 1, AgentReviewConclusion::Failure, 'important');

        $this->visit($document);

        self::assertSelectorTextContains('[data-testid="agent-review-summary"] .lp-status-chip', 'Agent review skipped');
        self::assertSelectorTextContains('[data-testid="agent-review-summary"] .lp-agent-review__meta', 'You published after round 1 · 1 important open');
        self::assertSelectorTextContains('[data-testid="agent-review-summary"]', 'Open. You published before the fix round ran.');
    }

    public function test_the_summary_sits_outside_the_prose_so_its_text_stays_the_version_text(): void
    {
        $document = $this->linkedDocument($this->card, DocumentStatus::InReview);
        $this->storeReview($document, 1, AgentReviewConclusion::Success, 'nit');

        $this->visit($document);

        self::assertSelectorNotExists('.lp-review-doc__prose [data-testid="agent-review-summary"]');
    }

    public function test_a_document_with_no_review_shows_no_summary(): void
    {
        $document = $this->linkedDocument($this->card, DocumentStatus::InReview);

        $this->visit($document);

        self::assertSelectorNotExists('[data-testid="agent-review-summary"]');
    }
}
