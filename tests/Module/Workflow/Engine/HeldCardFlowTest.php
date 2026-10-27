<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Command\ClaimWorkRequestCommand;
use App\Module\Bridge\Command\ClaimWorkRequestHandler;
use App\Module\Bridge\Command\ReleaseCardAgentsCommand;
use App\Module\Bridge\Command\ReleaseCardAgentsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestRefusal;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The shipped Lifecycle rules and the real handlers, through the sequence that let a bridge run work on an unmanaged card. */
final class HeldCardFlowTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_bridge_gets_no_work_of_a_card_held_and_moved_on_and_the_release_opens_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('held-card-flow');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'next');
        $owner = $project->owner;

        $this->move($card, 'tech-design');
        $this->evaluate($card, '2026-10-05 15:00:00');
        $request = $this->onlyRequest($card);
        self::assertSame(['tech-design', WorkRequestState::Open], [$request->kind, $request->state]);
        $bridge = new Bridge(AgentCredential::managed($this->em(), $owner, $owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = array_values(array_filter([Bridge::CAPABILITY_WORK_REQUESTS, $request->capability]));
        $this->em()->persist($bridge);
        $this->em()->flush();
        self::assertCount(1, $this->offers($project->id, $bridge));

        // Lifecycle has no move from Tech design to Next, so the move holds the card.
        $this->move($this->fresh($card), 'next', unmanage: true);
        $this->evaluate($card, '2026-10-05 15:10:00');

        self::assertSame([], $this->offers($project->id, $bridge));
        $claim = $this->service(ClaimWorkRequestHandler::class)(new ClaimWorkRequestCommand($owner, $bridge->id, $request->id ?? throw new \LogicException('A flushed request has an id.')));
        self::assertNull($claim->request);
        self::assertSame(WorkRequestRefusal::CardHeld, $claim->refusal);
        self::assertSame(WorkRequestState::Cancelled, $this->onlyRequest($card)->state);

        $card = $this->fresh($card);
        $this->service(ReleaseCardAgentsHandler::class)(new ReleaseCardAgentsCommand($card->project, $this->idOf($card), $card->project->owner));
        $this->evaluate($card, '2026-10-05 16:00:00');
        $this->evaluate($card, '2026-10-05 16:01:00');

        self::assertSame('next', $this->fresh($card)->column->slug);
        self::assertSame(WorkRequestState::Cancelled, $this->onlyRequest($card)->state);
        self::assertSame([], $this->offers($project->id, $bridge));
    }

    private function move(Card $card, string $column, bool $unmanage = false): void
    {
        $this->service(UpdateCardHandler::class)(new UpdateCardCommand(
            card: $card,
            actor: Actor::Human,
            column: $this->column($card->project, $column),
            unmanageBy: $unmanage ? $card->project->owner : null,
        ));
    }

    /** @return list<WorkRequest> */
    private function offers(?Uuid $projectId, Bridge $bridge): array
    {
        return $this->service(WorkRequestRepository::class)->findOpenOffers($bridge->id, [(string) $projectId], $bridge->capabilities ?? [], 10, []);
    }

    private function onlyRequest(Card $card): WorkRequest
    {
        $this->em()->clear();
        $requests = $this->service(WorkRequestRepository::class)->findBy(['subjectId' => $this->idOf($card)]);
        self::assertCount(1, $requests);

        return $requests[0];
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
