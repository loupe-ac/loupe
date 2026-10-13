<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardHeldByBlocker;
use App\Module\Workflow\Contract\BlockerHold;
use App\Module\Workflow\Contract\Evidence;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Fact\BlockerHoldFacts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\TestCase;

final class CardHeldByBlockerTest extends TestCase
{
    public function test_a_card_that_a_blocker_alone_holds_shows_the_blocker_since_the_hold(): void
    {
        $since = new \DateTimeImmutable('2026-10-01 09:10:00');
        $facts = self::facts(new BlockerHold('card', $since, 12, 'Ship the API'));

        self::assertTrue(new CardHeldByBlocker()->evaluate($facts, []));
        self::assertEquals(new Evidence($since, ['%number%' => 12, '%title%' => 'Ship the API']), new CardHeldByBlocker()->evidence($facts, []));
    }

    public function test_a_card_with_no_hold_is_not_held(): void
    {
        $facts = self::facts(null);

        self::assertFalse(new CardHeldByBlocker()->evaluate($facts, []));
        self::assertNull(new CardHeldByBlocker()->evidence($facts, []));
    }

    private static function facts(?BlockerHold $hold): Facts
    {
        return FactsMother::facts(provided: [BlockerHoldFacts::class => new BlockerHoldFacts($hold)]);
    }
}
