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
    public function test_a_card_check_goes_to_the_workflow_transport_and_not_to_the_shared_one(): void
    {
        self::bootKernel();
        $workflow = $this->transport('workflow');
        $async = $this->transport('async');
        $workflow->reset();
        $async->reset();
        $trigger = self::getContainer()->get(EvaluationTrigger::class);
        self::assertInstanceOf(EvaluationTrigger::class, $trigger);
        $cardId = Uuid::v7();

        $trigger->forCards([$cardId]);

        self::assertEquals([new EvaluateCard($cardId->toRfc4122())], array_map(static fn ($envelope): object => $envelope->getMessage(), $workflow->getSent()));
        self::assertSame([], $async->getSent());
    }

    public function test_the_sweep_keeps_its_own_low_priority_transport(): void
    {
        self::bootKernel();
        $workflow = $this->transport('workflow');
        $low = $this->transport('async_low');
        $workflow->reset();
        $low->reset();
        $trigger = self::getContainer()->get(EvaluationTrigger::class);
        self::assertInstanceOf(EvaluationTrigger::class, $trigger);

        $trigger->inBackground([Uuid::v7()]);

        self::assertCount(1, $low->getSent());
        self::assertSame([], $workflow->getSent());
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
