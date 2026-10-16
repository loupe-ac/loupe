<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\DocumentReviewAnswer;
use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\AgentReview\Service\DocumentAgentReviewReader;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Mcp\DocumentGetReviewTool;
use App\Module\Review\Service\DocumentAgentReviews;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentAgentReviewReaderTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('document-review-reader');
        $this->card = $this->card($this->project);
        $this->em->flush();
    }

    public function test_the_port_is_implemented_by_the_agent_review_module(): void
    {
        self::assertInstanceOf(DocumentAgentReviewReader::class, self::getContainer()->get(DocumentAgentReviews::class));
    }

    public function test_it_lists_the_reviews_newest_first_with_the_answer_of_each_finding(): void
    {
        $document = $this->linkedDocument($this->card, versions: 2);
        $first = $this->documentReview($this->project, $this->card, $document, 2, 1);
        $first->answer([new DocumentReviewFindingAnswer(0, DocumentReviewAnswer::Fixed), new DocumentReviewFindingAnswer(1, DocumentReviewAnswer::NotChanged, 'The code proves it.')]);
        $second = $this->documentReview($this->project, $this->card, $document, 1, 2);
        $this->em->flush();
        $this->em->clear();
        $document = $this->em->find($document::class, $document->id);
        self::assertNotNull($document);

        $reader = self::getContainer()->get(DocumentAgentReviews::class);
        self::assertInstanceOf(DocumentAgentReviews::class, $reader);
        $reviews = $reader->forDocument($document);

        self::assertSame([(string) $second->id, (string) $first->id], array_column($reviews, 'reviewId'));
        self::assertSame([2, 1], array_column($reviews, 'versionNumber'));
        self::assertSame([false, true], array_column($reviews, 'answered'));
        self::assertNull($reviews[0]['findings'][0]['answer']);
        self::assertSame(['answer' => 'fixed', 'reason' => null], $reviews[1]['findings'][0]['answer']);
        self::assertSame(['answer' => 'not-changed', 'reason' => 'The code proves it.'], $reviews[1]['findings'][1]['answer']);
        self::assertSame([0, 1], array_column($reviews[1]['findings'], 'index'));
        self::assertSame(['important', 'Finding 1', 'Body 1', false], [$reviews[1]['findings'][0]['severity'], $reviews[1]['findings'][0]['title'], $reviews[1]['findings'][0]['body'], $reviews[1]['findings'][0]['needsPerson']]);
        self::assertNotNull($reviews[1]['answeredAt']);
    }

    public function test_a_document_with_no_review_lists_none(): void
    {
        $document = $this->linkedDocument($this->card);
        $reader = self::getContainer()->get(DocumentAgentReviews::class);
        self::assertInstanceOf(DocumentAgentReviews::class, $reader);

        self::assertSame([], $reader->forDocument($document));
    }

    public function test_document_get_review_reports_the_agent_reviews(): void
    {
        $document = $this->linkedDocument($this->card);
        $review = $this->documentReview($this->project, $this->card, $document, 1);
        $this->actAsMcpTokenBoundTo($this->project);
        $tool = self::getContainer()->get(DocumentGetReviewTool::class);
        self::assertInstanceOf(DocumentGetReviewTool::class, $tool);

        $payload = $tool((string) $document->id);

        self::assertSame([(string) $review->id], array_column($payload['agentReviews'], 'reviewId'));
        self::assertSame('failure', $payload['agentReviews'][0]['conclusion']);
    }
}
