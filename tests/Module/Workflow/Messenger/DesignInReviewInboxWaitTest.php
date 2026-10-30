<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Messenger\ReconcileCardWaitsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** A blocked Tech design card, run through the real engine and the real inbox reconcile. */
final class DesignInReviewInboxWaitTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_tech_design_in_review_opens_a_wait_while_a_blocker_holds_the_card_and_the_approval_hands_over_to_the_blocker(): void
    {
        [$card, $document] = $this->blockedCardWithTechDesignInReview();

        $card = $this->evaluateAndReconcile($card);

        self::assertContains('document-in-review', $this->markerKeys($card));
        self::assertNotContains('held-by-blocker', $this->markerKeys($card));
        self::assertSame(['review'], $this->openAutomaticItemKinds($card));

        $approved = $this->em()->find(Document::class, $document->id) ?? throw new \LogicException('The document stays.');
        $approved->status = DocumentStatus::Approved;
        $this->em()->flush();
        $card = $this->evaluateAndReconcile($card);

        self::assertSame('tech-design', $card->column->slug);
        self::assertNotContains('document-in-review', $this->markerKeys($card));
        self::assertContains('held-by-blocker', $this->markerKeys($card));
        self::assertSame([], $this->openAutomaticItemKinds($card));
    }

    /** @return array{Card, Document} */
    private function blockedCardWithTechDesignInReview(): array
    {
        self::bootKernel();
        $this->enableInbox();
        $project = $this->workflowProject('design-in-review-wait');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->em()->persist(new CardLink($this->card($project, 'next'), $card, CardLinkKind::Blocks));

        $document = new Document($project->owner, $project, 'Tech design');
        $document->addVersion('# Tech design', '<h1>Tech design</h1>');
        $tag = new Tag($project, 'tech-design');
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return [$card, $document];
    }

    /** Reads the card again, because the engine and the reconcile store what they write. */
    private function evaluateAndReconcile(Card $card): Card
    {
        $handler = new EvaluateCardHandler(new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock('2026-10-02 12:00:00')), $this->service(WorkflowQueuedEvaluationRepository::class));
        $handler(new EvaluateCard((string) $card->id));
        $this->em()->flush();
        $this->service(ReconcileCardWaitsHandler::class)(new ReconcileCardWaits((string) $card->project->id, [(string) $card->id]));
        $this->em()->clear();

        return $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card stays.');
    }

    /** @return list<string> */
    private function markerKeys(Card $card): array
    {
        return array_map(static fn (array $marker): string => $marker['key'], $card->markers ?? []);
    }

    /** @return list<string> */
    private function openAutomaticItemKinds(Card $card): array
    {
        return $this->em()->getConnection()->fetchFirstColumn("SELECT kind FROM inbox_items WHERE project_id = :id AND origin = 'loupe' AND state = 'open'", ['id' => $card->project->id]);
    }

    private function enableInbox(): void
    {
        $this->service(FeatureFlagRepository::class)->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
        $this->em()->flush();
        $reader = $this->service(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }
}
