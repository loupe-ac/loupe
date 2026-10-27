<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Fact\WorkHoldFactProvider;
use App\Module\Workflow\Fact\WorkHoldFacts;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkHoldFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_card_with_a_marked_rule_state_reads_the_stamp(): void
    {
        $card = $this->stateCard($this->stateProject('work-hold-fact'), 'next');
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('A persisted card has an id.'), $card->project, 'start-design');
        $state->heldByWorkHoldSince = new \DateTimeImmutable('2026-10-02 09:10:00');
        $this->em()->persist($state);
        $this->em()->flush();

        $facts = $this->build($card);

        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:10:00'), $facts->since);
        self::assertTrue($this->provider()->fingerprint($facts));
    }

    public function test_a_card_with_no_mark_reads_none(): void
    {
        $card = $this->stateCard($this->stateProject('work-hold-fact-none'), 'next');

        self::assertNull($this->build($card)->since);
        self::assertFalse($this->provider()->fingerprint($this->build($card)));
    }

    private function build(Card $card): WorkHoldFacts
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(WorkHoldFacts::class, $facts);

        return $facts;
    }

    private function provider(): WorkHoldFactProvider
    {
        $provider = self::getContainer()->get(WorkHoldFactProvider::class);
        self::assertInstanceOf(WorkHoldFactProvider::class, $provider);

        return $provider;
    }
}
