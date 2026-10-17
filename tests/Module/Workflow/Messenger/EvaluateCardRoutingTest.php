<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class EvaluateCardRoutingTest extends KernelTestCase
{
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

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
