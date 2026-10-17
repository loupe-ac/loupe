<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\EventListener;

use App\Module\AgentReview\Entity\AgentReviewPost;
use App\Module\AgentReview\EventListener\DeleteAgentReviewsOnProjectDeleting;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteAgentReviewsOnProjectDeletingTest extends KernelTestCase
{
    use DocumentReviewScenario;

    private EntityManagerInterface $em;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    /** The listener runs alone here, so no cascade from the project, the card or the pull request can hide it. */
    public function test_the_listener_deletes_the_reviews_of_its_project_only(): void
    {
        $doomed = $this->makeProject('agent-review-listener-doomed');
        $spared = $this->makeProject('agent-review-listener-spared');
        $doomedReview = $this->review($this->card($doomed), $this->pullRequest($doomed));
        $sparedReview = $this->review($this->card($spared), $this->pullRequest($spared));
        $this->em->persist(new AgentReviewPost($doomedReview, str_repeat('e', 40), true));
        $this->em->persist(new AgentReviewPost($sparedReview, str_repeat('e', 40), true));
        $this->em->flush();
        $this->documentReview($doomed, $doomedReview->card, $this->linkedDocument($doomedReview->card));
        $this->documentReview($spared, $sparedReview->card, $this->linkedDocument($sparedReview->card));
        self::assertSame(1, $this->documentReviewsFor((string) $doomed->id));

        self::assertSame(1, $this->countFor((string) $doomed->id));
        self::assertSame(1, $this->postsOf((string) $doomedReview->id));

        $listener = self::getContainer()->get(DeleteAgentReviewsOnProjectDeleting::class);
        self::assertInstanceOf(DeleteAgentReviewsOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(0, $this->countFor((string) $doomed->id));
        self::assertSame(1, $this->countFor((string) $spared->id));
        self::assertSame(0, $this->documentReviewsFor((string) $doomed->id));
        self::assertSame(1, $this->documentReviewsFor((string) $spared->id));
        self::assertSame(0, $this->postsOf((string) $doomedReview->id), 'The foreign key deletes the posts of a review that a DQL delete removes.');
        self::assertSame(1, $this->postsOf((string) $sparedReview->id));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = :id', ['id' => (string) $doomed->id]));
    }

    /** Covers the listener order the container uses, with the card and pull request deletes of the other modules. */
    public function test_deleting_a_project_with_reviews_succeeds(): void
    {
        $doomed = $this->makeProject('agent-review-delete-doomed');
        $spared = $this->makeProject('agent-review-delete-spared');
        $this->review($this->card($doomed), $this->pullRequest($doomed));
        $this->review($this->card($spared), $this->pullRequest($spared));
        $this->em->flush();
        $doomedId = (string) $doomed->id;

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => $doomedId]));
        self::assertSame(0, $this->countFor($doomedId));
        self::assertSame(1, $this->countFor((string) $spared->id));
    }

    private function documentReviewsFor(string $projectId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM document_agent_reviews WHERE project_id = :id', ['id' => $projectId]);
    }

    private function postsOf(string $reviewId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agent_review_posts WHERE review_id = :id', ['id' => $reviewId]);
    }

    private function countFor(string $projectId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM agent_reviews WHERE project_id = :id', ['id' => $projectId]);
    }
}
