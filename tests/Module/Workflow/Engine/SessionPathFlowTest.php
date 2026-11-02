<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Command\OpenInteractiveRun;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The shipped Lifecycle rules and the real engine, from a session that brings its card to Implementation to the end of that session. */
final class SessionPathFlowTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_card_that_a_session_brought_to_implementation_starts_no_worker(): void
    {
        [$card] = $this->sessionCard();

        $this->evaluate($card, '2026-11-02 10:01:00');

        self::assertSame('in-progress', $this->fresh($card)->column->slug);
        self::assertSame([], $this->implementRequests($card));
    }

    public function test_the_card_goes_back_to_backlog_when_the_session_ends_with_no_pull_request(): void
    {
        [$card, $session] = $this->sessionCard();
        $this->evaluate($card, '2026-11-02 10:01:00');

        $this->service(InteractiveRuns::class)->close($card->project, $this->idOf($card), $session);
        $this->evaluate($card, '2026-11-02 10:30:00');
        $this->evaluate($card, '2026-11-02 10:31:00');

        $card = $this->fresh($card);
        self::assertSame('backlog', $card->column->slug);
        self::assertSame([], $this->implementRequests($card));
    }

    public function test_the_card_stays_in_implementation_when_the_session_ends_after_the_pull_request_link(): void
    {
        [$card, $session] = $this->sessionCard();
        $this->pullRequest($card);
        $this->em()->flush();

        $this->service(InteractiveRuns::class)->close($card->project, $this->idOf($card), $session);
        $this->evaluate($card, '2026-11-02 10:30:00');
        $this->evaluate($card, '2026-11-02 10:31:00');

        $card = $this->fresh($card);
        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->implementRequests($card));
    }

    public function test_a_card_that_a_person_moved_to_implementation_still_starts_a_worker(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('session-path-person');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');

        $this->evaluate($card, '2026-11-02 10:00:00');

        self::assertCount(1, $this->implementRequests($card));
    }

    /** @return array{Card, Uuid} */
    private function sessionCard(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('session-path');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'backlog');
        $session = Uuid::v4();

        $this->service(UpdateCardHandler::class)(new UpdateCardCommand(
            card: $card,
            actor: Actor::Agent,
            column: $this->column($project, 'in-progress'),
            openInteractiveRun: new OpenInteractiveRun($session, 'loupe:lifecycle-session'),
        ));
        $this->evaluate($card, '2026-11-02 10:00:00');

        return [$card, $session];
    }

    /** @return list<WorkRequest> */
    private function implementRequests(Card $card): array
    {
        return array_values(array_filter(
            $this->service(WorkRequestRepository::class)->findBy(['subjectId' => $card->id]),
            static fn (WorkRequest $request): bool => 'implement' === $request->kind,
        ));
    }

    private function evaluate(Card $card, string $at): void
    {
        $this->service(Engine::class)->evaluate($this->idOf($card), new \DateTimeImmutable($at));
    }

    private function fresh(Card $card): Card
    {
        $this->em()->clear();

        return $this->em()->find(Card::class, $this->idOf($card)) ?? throw new \LogicException('The card stays stored.');
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A flushed card has an id.');
    }
}
