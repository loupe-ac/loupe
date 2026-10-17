<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Service\EvaluationTrigger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

final class EvaluationTriggerTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    /** @var list<object> */
    private array $stamps = [];

    public function test_it_queues_one_evaluation_per_unique_card(): void
    {
        $one = Uuid::v7();
        $two = Uuid::v7();

        $this->trigger()->forCards([$one, $two->toRfc4122(), $one->toRfc4122(), $two, Uuid::fromString($one->toBase58())]);

        self::assertEquals([new EvaluateCard($one->toRfc4122()), new EvaluateCard($two->toRfc4122())], $this->dispatched);
    }

    public function test_it_queues_a_background_evaluation_on_the_low_priority_transport(): void
    {
        $one = Uuid::v7();

        $this->trigger()->inBackground([$one, $one->toRfc4122()]);

        self::assertEquals([new EvaluateCard($one->toRfc4122())], $this->dispatched);
        self::assertEquals([new TransportNamesStamp(['card_sweep'])], $this->stamps);
    }

    public function test_it_is_always_on(): void
    {
        self::assertTrue($this->trigger()->isOn());
    }

    private function trigger(): EvaluationTrigger
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $this->dispatched[] = $message;
            array_push($this->stamps, ...$stamps);

            return new Envelope($message);
        });

        return new EvaluationTrigger($bus);
    }
}
