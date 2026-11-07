<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EvaluateCardRoutingTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_card_check_goes_to_the_card_eval_transport_and_not_to_the_shared_one(): void
    {
        self::bootKernel();
        $cardEval = $this->transport('card_eval');
        $jobs = $this->transport('jobs');
        $cardEval->reset();
        $jobs->reset();
        $trigger = self::getContainer()->get(EvaluationTrigger::class);
        self::assertInstanceOf(EvaluationTrigger::class, $trigger);
        $cardId = Uuid::v7();

        $trigger->forCards([$cardId]);

        self::assertEquals([new EvaluateCard($cardId->toRfc4122())], array_map(static fn ($envelope): object => $envelope->getMessage(), $cardEval->getSent()));
        self::assertSame([], $jobs->getSent());
    }

    public function test_the_sweep_keeps_its_own_low_priority_transport(): void
    {
        self::bootKernel();
        $cardEval = $this->transport('card_eval');
        $cardSweep = $this->transport('card_sweep');
        $cardEval->reset();
        $cardSweep->reset();
        $trigger = self::getContainer()->get(EvaluationTrigger::class);
        self::assertInstanceOf(EvaluationTrigger::class, $trigger);

        $trigger->inBackground([Uuid::v7()]);

        self::assertCount(1, $cardSweep->getSent());
        self::assertSame([], $cardEval->getSent());
    }

    public function test_a_move_by_a_person_reaches_the_interactive_queue_for_the_moved_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('fast-route-human');
        $card = $this->card($project, 'backlog');
        $interactive = $this->transport('interactive');
        $interactive->reset();

        $this->move($card, $project, Actor::Human);

        $cardId = (string) $card->id;
        self::assertEquals([new EvaluateCard($cardId, personWaiting: true)], $this->messages($interactive));
    }

    public function test_a_move_by_an_agent_stays_on_the_card_eval_queue(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('fast-route-agent');
        $card = $this->card($project, 'backlog');
        $interactive = $this->transport('interactive');
        $cardEval = $this->transport('card_eval');
        $interactive->reset();
        $cardEval->reset();

        $this->move($card, $project, Actor::Agent);

        self::assertSame([], $interactive->getSent());
        self::assertContainsEquals(new EvaluateCard((string) $card->id), $this->messages($cardEval));
    }

    private function move(\App\Module\Board\Entity\Card $card, \App\Module\Project\Entity\Project $project, Actor $actor): void
    {
        $handler = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $handler);
        $handler(new UpdateCardCommand($card, $actor, column: $this->column($project, 'next')));
    }

    /** @return list<EvaluateCard> */
    private function messages(InMemoryTransport $transport): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $m): bool => $m instanceof EvaluateCard,
        ));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
