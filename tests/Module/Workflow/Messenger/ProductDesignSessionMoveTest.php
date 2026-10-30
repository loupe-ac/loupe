<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Board\Mcp\CardRunCloseTool;
use App\Module\Board\Mcp\CardRunOpenTool;
use App\Module\Board\Mcp\CardUpdateTool;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Review\Mcp\DocumentCreateTool;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\McpTokenScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

/** A Lifecycle card that card_run_open moves into Product design, run through the real tool and the real engine. */
final class ProductDesignSessionMoveTest extends KernelTestCase
{
    use ActionScenario;
    use McpTokenScenario;

    private const string SKILL = 'loupe:product-design';

    public function test_a_session_that_opens_with_the_move_gets_no_second_session(): void
    {
        $card = $this->lifecycleCard('session-move');

        $this->service(CardRunOpenTool::class)((string) Uuid::v7(), self::SKILL, (string) $card->id, status: 'product-design');
        $this->evaluate($card, '2026-10-02 12:00:00');

        self::assertSame('product-design', $this->reload($card)->column->slug);
        self::assertSame([], $this->sessionRequests($card));
    }

    public function test_the_card_gets_a_session_once_the_session_that_moved_it_closes(): void
    {
        $card = $this->lifecycleCard('session-move-close');
        $sessionId = (string) Uuid::v7();
        $this->service(CardRunOpenTool::class)($sessionId, self::SKILL, (string) $card->id, status: 'product-design');
        $this->evaluate($card, '2026-10-02 12:00:00');

        $this->service(CardRunCloseTool::class)($sessionId, (string) $card->id);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertCount(1, $this->sessionRequests($card));
    }

    public function test_a_draft_linked_before_the_session_closes_gets_no_session(): void
    {
        $card = $this->lifecycleCard('session-move-draft');
        $sessionId = (string) Uuid::v7();
        $this->service(CardRunOpenTool::class)($sessionId, self::SKILL, (string) $card->id, status: 'product-design');
        $this->evaluate($card, '2026-10-02 12:00:00');

        $draft = $this->service(DocumentCreateTool::class)('Product design: session move', '# Product design', tags: ['product-design'], draft: true);
        $this->service(CardUpdateTool::class)((string) $card->id, documentIds: [$draft['documentId']]);
        $this->service(CardRunCloseTool::class)($sessionId, (string) $card->id);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame([], $this->sessionRequests($card));
    }

    private function lifecycleCard(string $name): Card
    {
        self::bootKernel();
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);
        $this->actAsMcpTokenBoundTo($project);

        return $this->card($project, 'next');
    }

    private function evaluate(Card $card, string $now): void
    {
        $handler = new EvaluateCardHandler(new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock($now)), new EventDispatcher());
        $handler(new EvaluateCard((string) $card->id));
        $this->em()->flush();
    }

    private function reload(Card $card): Card
    {
        $this->em()->clear();

        return $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card stays.');
    }

    /** @return list<WorkRequest> */
    private function sessionRequests(Card $card): array
    {
        return $this->service(WorkRequestRepository::class)->findBy(['subjectType' => WorkSubject::CARD, 'subjectId' => $card->id, 'ruleId' => 'product-design-session']);
    }
}
