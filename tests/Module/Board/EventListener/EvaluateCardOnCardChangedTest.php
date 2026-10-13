<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Board\EventListener\EvaluateCardOnCardChanged;
use App\Module\Workflow\Contract\CardEvaluations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EvaluateCardOnCardChangedTest extends TestCase
{
    public function test_an_update_queues_a_check_of_the_card(): void
    {
        $cardId = Uuid::v7();
        $trigger = $this->createMock(CardEvaluations::class);
        $trigger->expects($this->once())->method('forCards')->with([$cardId]);

        new EvaluateCardOnCardChanged($trigger)(new CardChanged(Uuid::v7(), $cardId, CardChanged::UPDATED, false));
    }

    public function test_the_update_of_the_marker_pass_queues_nothing(): void
    {
        $trigger = $this->createMock(CardEvaluations::class);
        $trigger->expects($this->never())->method('forCards');

        new EvaluateCardOnCardChanged($trigger)(new CardChanged(Uuid::v7(), Uuid::v7(), CardChanged::UPDATED, false, markersOnly: true));
    }

    public function test_a_deleted_card_queues_nothing(): void
    {
        $trigger = $this->createMock(CardEvaluations::class);
        $trigger->expects($this->never())->method('forCards');

        new EvaluateCardOnCardChanged($trigger)(new CardChanged(Uuid::v7(), Uuid::v7(), CardChanged::DELETED, false));
    }

    public function test_a_new_card_is_left_to_its_own_listener(): void
    {
        $trigger = $this->createMock(CardEvaluations::class);
        $trigger->expects($this->never())->method('forCards');

        new EvaluateCardOnCardChanged($trigger)(new CardChanged(Uuid::v7(), Uuid::v7(), CardChanged::CREATED, false));
    }
}
