<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Fact\ScheduledRetryFactProvider;
use App\Module\Workflow\Fact\ScheduledRetryFacts;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScheduledRetryFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_card_reads_the_due_times_of_its_refused_rules_the_earliest_first(): void
    {
        $project = $this->stateProject('scheduled-retry');
        $card = $this->stateCard($project);
        $other = $this->stateCard($project);
        $this->ruleState($card, 'fix-checks', '2026-10-02 12:30:00', 'failed');
        $this->ruleState($card, 'implement', '2026-10-02 12:10:00', 'timeout');
        $this->ruleState($card, 'merge', '2026-10-02 12:05:00', null);
        $this->ruleState($card, 'review', null, 'failed');
        $this->ruleState($other, 'implement', '2026-10-02 12:01:00', 'failed');

        self::assertEquals(
            [new \DateTimeImmutable('2026-10-02 12:10:00'), new \DateTimeImmutable('2026-10-02 12:30:00')],
            $this->build($card)->dueAts,
        );
    }

    public function test_a_card_with_no_scheduled_retry_reads_none(): void
    {
        $card = $this->stateCard($this->stateProject('scheduled-retry-none'));

        self::assertSame([], $this->build($card)->dueAts);
    }

    public function test_the_fingerprint_ignores_the_due_times_so_a_refusal_never_counts_as_a_change_of_facts(): void
    {
        $card = $this->stateCard($this->stateProject('scheduled-retry-print'));
        $none = $this->build($card);
        $this->ruleState($card, 'implement', '2026-10-02 12:10:00', 'failed');

        self::assertSame($this->provider()->fingerprint($none), $this->provider()->fingerprint($this->build($card)));
    }

    private function ruleState(Card $card, string $ruleId, ?string $dueAt, ?string $refusal): void
    {
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('A persisted card has an id.'), $card->project, $ruleId);
        $state->dueAt = null === $dueAt ? null : new \DateTimeImmutable($dueAt);
        $state->lastRefusal = $refusal;
        $this->em()->persist($state);
        $this->em()->flush();
    }

    private function build(Card $card): ScheduledRetryFacts
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(ScheduledRetryFacts::class, $facts);

        return $facts;
    }

    private function provider(): ScheduledRetryFactProvider
    {
        $provider = self::getContainer()->get(ScheduledRetryFactProvider::class);
        self::assertInstanceOf(ScheduledRetryFactProvider::class, $provider);

        return $provider;
    }
}
