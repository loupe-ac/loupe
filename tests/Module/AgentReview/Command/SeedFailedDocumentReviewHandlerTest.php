<?php

declare(strict_types=1);

namespace App\Tests\Module\AgentReview\Command;

use App\Module\AgentReview\Command\SeedFailedDocumentReviewCommand;
use App\Module\AgentReview\Command\SeedFailedDocumentReviewHandler;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SeedFailedDocumentReviewHandlerTest extends KernelTestCase
{
    use DocumentReviewScenario;
    use WorkflowProjects;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
    }

    public function test_a_document_no_card_links_gets_no_review(): void
    {
        $project = $this->workflowProject('seed-failed-review-unlinked');
        $card = new Card($project, $this->column($project, 'tech-design'), 'Ship it', '', 1);
        $this->em->persist($card);
        $this->em->flush();
        $document = $this->linkedDocument($card);
        $unlinked = new \App\Module\Review\Entity\Document($project->owner, $project, 'Loose');
        $unlinked->addVersion('Text', '<p>Text</p>');
        $this->em->persist($unlinked);
        $this->em->flush();
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $handler = new SeedFailedDocumentReviewHandler($this->em, new DocumentAgentReviewRepository($registry), new CardDocumentRepository($registry), $dispatcher);

        self::assertNull($handler(new SeedFailedDocumentReviewCommand($unlinked)));
        self::assertNotNull($handler(new SeedFailedDocumentReviewCommand($document)));
    }

    public function test_each_call_adds_a_failed_round_and_reports_the_card(): void
    {
        $project = $this->workflowProject('seed-failed-review');
        $card = new Card($project, $this->column($project, 'tech-design'), 'Ship it', '', 1);
        $this->em->persist($card);
        $this->em->flush();
        $document = $this->linkedDocument($card);

        $reported = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use (&$reported): void {
            $reported[] = $event;
        });
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $reviews = new DocumentAgentReviewRepository($registry);
        $handler = new SeedFailedDocumentReviewHandler($this->em, $reviews, new CardDocumentRepository($registry), $dispatcher);

        self::assertNotNull($handler(new SeedFailedDocumentReviewCommand($document)));
        self::assertNotNull($handler(new SeedFailedDocumentReviewCommand($document)));

        self::assertSame(2, $reviews->countRoundsByDocumentForCard($card)[(string) $document->id]['failed']);
        self::assertCount(2, $reported);
        self::assertSame((string) $card->id, (string) $reported[0]->cardId);
        self::assertTrue($reported[0]->markersOnly);
    }
}
