<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Fact\BlockerHoldFactProvider;
use App\Module\Workflow\Fact\BlockerHoldFacts;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BlockerHoldFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_card_that_a_move_rule_holds_for_an_open_blocker_reads_the_hold(): void
    {
        $card = $this->stateCard($this->stateProject('blocker-hold'), 'tech-design');
        $blocker = $this->holdByBlocker($card, '2026-10-02 09:10:00');

        $hold = $this->build($card)->hold;

        self::assertNotNull($hold);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:10:00'), $hold->since);
        self::assertSame([$blocker->number, $blocker->title], [$hold->blockerNumber, $hold->blockerTitle]);
        self::assertSame($blocker->number, $this->provider()->fingerprint($this->build($card)));
    }

    public function test_a_card_with_no_hold_reads_none(): void
    {
        $card = $this->stateCard($this->stateProject('blocker-hold-none'), 'tech-design');

        self::assertNull($this->build($card)->hold);
        self::assertNull($this->provider()->fingerprint($this->build($card)));
    }

    private function build(Card $card): BlockerHoldFacts
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(BlockerHoldFacts::class, $facts);

        return $facts;
    }

    private function provider(): BlockerHoldFactProvider
    {
        $provider = self::getContainer()->get(BlockerHoldFactProvider::class);
        self::assertInstanceOf(BlockerHoldFactProvider::class, $provider);

        return $provider;
    }
}
