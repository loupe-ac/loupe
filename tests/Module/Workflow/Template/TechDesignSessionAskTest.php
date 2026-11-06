<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Template;

use App\Module\Board\Workflow\CardValueFacts;
use App\Module\Board\Workflow\CardValueWrite;
use App\Module\Board\Workflow\ColumnArrivalFacts;
use App\Module\Bridge\Workflow\SessionFacts;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The rules of the Lifecycle template that ask before a tech design session starts on a self-approved product design. */
final class TechDesignSessionAskTest extends KernelTestCase
{
    private const string SWITCH = 'tech-design-interactive';
    private const string GRANT = 'session-start-approved';
    private const string ARRIVED = '2026-10-22 10:00:00.500000';
    private const string APPROVED = '2026-10-22 10:05:00.250000';
    private const string AFTER = '2026-10-22 10:10:00';
    private const string BEFORE = '2026-10-22 10:03:00';

    public function test_a_self_approval_with_the_switch_on_asks_and_requests_no_session(): void
    {
        $facts = self::facts(approvedBy: 'workflow');

        self::assertSame(['tech-design-session-ask'], $this->firing($facts));
        $ask = $this->actionsOf('tech-design-session-ask')[0];
        self::assertSame('ask', $ask->key);
        self::assertSame('workflow.ask.tech_design_session', $ask->params['question']);
        self::assertCount(2, $ask->options);
    }

    public function test_a_person_approval_with_the_switch_on_requests_the_session_and_asks_nothing(): void
    {
        self::assertSame(['tech-design-session'], $this->firing(self::facts(approvedBy: 'person')));
    }

    public function test_a_card_with_no_approval_requests_the_session_and_asks_nothing(): void
    {
        self::assertSame(['tech-design-session'], $this->firing(self::facts(approvedBy: null)));
    }

    public function test_the_start_answer_requests_the_session_and_keeps_the_value(): void
    {
        $facts = self::facts(approvedBy: 'workflow', grant: [true, self::AFTER, Actor::System]);

        self::assertSame(['tech-design-session'], $this->firing($facts));
    }

    public function test_an_answer_in_the_same_second_as_the_approval_still_counts(): void
    {
        $facts = self::facts(approvedBy: 'workflow', grant: [true, '2026-10-22 10:05:00.250001', Actor::System]);

        self::assertSame(['tech-design-session'], $this->firing($facts));
    }

    public function test_a_value_older_than_the_newest_self_approval_asks_and_is_cleared(): void
    {
        $facts = self::facts(approvedBy: 'workflow', grant: [true, self::BEFORE, Actor::System]);

        self::assertSame(['tech-design-session-clear', 'tech-design-session-ask'], $this->firing($facts));
        self::assertNotContains('tech-design-session', $this->firing($facts));
    }

    public function test_a_person_turning_the_switch_on_after_a_self_approval_counts_as_presence(): void
    {
        $facts = self::facts(approvedBy: 'workflow', switchWrite: [self::AFTER, Actor::Human]);

        self::assertContains('tech-design-session-presence', $this->firing($facts));
        self::assertContainsEquals($this->setValue(self::GRANT, true), $this->actionsOf('tech-design-session-presence'));
    }

    public function test_a_switch_write_by_an_agent_or_an_api_key_is_not_presence(): void
    {
        foreach ([Actor::Agent, Actor::ApiKey, Actor::System] as $writer) {
            $facts = self::facts(approvedBy: 'workflow', switchWrite: [self::AFTER, $writer]);

            self::assertNotContains('tech-design-session-presence', $this->firing($facts), $writer->name);
            self::assertContains('tech-design-session-ask', $this->firing($facts), $writer->name);
        }
    }

    public function test_a_switch_write_in_the_second_of_the_approval_but_before_it_is_not_presence(): void
    {
        $facts = self::facts(approvedBy: 'workflow', switchWrite: ['2026-10-22 10:05:00.100000', Actor::Human]);

        self::assertNotContains('tech-design-session-presence', $this->firing($facts));
        self::assertContains('tech-design-session-ask', $this->firing($facts));
    }

    public function test_a_switch_write_before_the_arrival_is_not_presence(): void
    {
        $facts = self::facts(approvedBy: 'workflow', switchWrite: ['2026-10-22 09:59:00', Actor::Human]);

        self::assertNotContains('tech-design-session-presence', $this->firing($facts));
        self::assertContains('tech-design-session-ask', $this->firing($facts));
    }

    public function test_the_presence_rule_needs_a_self_approval(): void
    {
        $facts = self::facts(approvedBy: 'person', switchWrite: [self::AFTER, Actor::Human]);

        self::assertNotContains('tech-design-session-presence', $this->firing($facts));
    }

    public function test_a_self_approval_and_then_a_person_approving_another_design_in_one_second_requests_a_session(): void
    {
        $facts = self::factsWithDocuments([
            self::approvedDocument('one', '2026-10-22 10:05:00.100000', true),
            self::approvedDocument('two', '2026-10-22 10:05:00.700000', false),
        ]);

        self::assertSame(['tech-design-session'], $this->firing($facts));
    }

    public function test_a_person_approval_and_then_a_self_approval_of_another_design_in_one_second_asks(): void
    {
        $facts = self::factsWithDocuments([
            self::approvedDocument('one', '2026-10-22 10:05:00.100000', false),
            self::approvedDocument('two', '2026-10-22 10:05:00.700000', true),
        ]);

        self::assertSame(['tech-design-session-ask'], $this->firing($facts));
    }

    public function test_an_open_session_clears_the_value_and_asks_nothing(): void
    {
        $facts = self::facts(approvedBy: 'workflow', grant: [true, self::AFTER, Actor::System], session: true);

        self::assertSame(['tech-design-session-clear'], $this->firing($facts));
        self::assertContainsEquals($this->setValue(self::GRANT, false), $this->actionsOf('tech-design-session-clear'));
    }

    public function test_turning_the_switch_off_clears_the_value(): void
    {
        $facts = self::facts(approvedBy: 'workflow', switchOn: false, grant: [true, self::AFTER, Actor::System]);

        self::assertContains('tech-design-session-clear', $this->firing($facts));
        self::assertNotContains('tech-design-session-ask', $this->firing($facts));
    }

    public function test_an_agent_that_a_person_sent_clears_the_value(): void
    {
        $facts = self::facts(
            approvedBy: 'workflow',
            grant: [true, self::AFTER, Actor::System],
            activeKinds: ['tech-design'],
            provided: [
                WorkRequestFacts::class => new WorkRequestFacts(['tech-design'], [], [], []),
                WorkerRunFacts::class => new WorkerRunFacts(['tech-design'], [], [], []),
            ],
        );

        self::assertContains('tech-design-session-clear', $this->firing($facts));
        self::assertNotContains('tech-design-session-ask', $this->firing($facts));
    }

    public function test_the_ask_waits_while_a_session_request_is_active(): void
    {
        $facts = self::facts(approvedBy: 'workflow', activeKinds: ['tech-design-session']);

        self::assertNotContains('tech-design-session-ask', $this->firing($facts));
    }

    public function test_the_session_request_is_withdrawn_when_the_value_is_not_current(): void
    {
        $cancelWhen = $this->rule('tech-design-session')->lead->cancelWhen;

        self::assertNotNull($cancelWhen);
        self::assertFalse($cancelWhen->evaluate(self::facts(approvedBy: 'workflow', grant: [true, self::AFTER, Actor::System])));
        self::assertTrue($cancelWhen->evaluate(self::facts(approvedBy: 'workflow', grant: [true, self::BEFORE, Actor::System])));
        self::assertTrue($cancelWhen->evaluate(self::facts(approvedBy: 'workflow')));
        self::assertFalse($cancelWhen->evaluate(self::facts(approvedBy: 'person')));
    }

    public function test_the_ask_options_write_the_value_or_turn_the_switch_off(): void
    {
        $options = $this->actionsOf('tech-design-session-ask')[0]->options;

        self::assertCount(2, $options);
        self::assertEquals([[$this->setValue(self::GRANT, true)], [$this->setValue(self::SWITCH, false)]], array_map(static fn ($option): array => $option->actions, $options));
    }

    /**
     * @param ?array{bool, string, Actor} $grant       the value, the write time and the writer of the start approval
     * @param ?array{string, Actor}       $switchWrite the write of the switch, or null for a write before the arrival
     * @param list<string>                $activeKinds
     * @param array<class-string, object> $provided
     */
    private static function facts(?string $approvedBy, bool $switchOn = true, ?array $grant = null, ?array $switchWrite = null, bool $session = false, array $activeKinds = [], array $provided = []): Facts
    {
        $documents = null === $approvedBy ? [] : [self::approvedDocument('product', self::APPROVED, 'workflow' === $approvedBy)];

        return self::factsWithDocuments($documents, $switchOn, $grant, $switchWrite, $session, $activeKinds, $provided);
    }

    /**
     * @param list<DocumentFacts>         $documents
     * @param ?array{bool, string, Actor} $grant
     * @param ?array{string, Actor}       $switchWrite
     * @param list<string>                $activeKinds
     * @param array<class-string, object> $provided
     */
    private static function factsWithDocuments(array $documents, bool $switchOn = true, ?array $grant = null, ?array $switchWrite = null, bool $session = false, array $activeKinds = [], array $provided = []): Facts
    {
        $values = [self::SWITCH => $switchOn];
        $writes = [self::SWITCH => new CardValueWrite(new \DateTimeImmutable($switchWrite[0] ?? '2026-10-22 09:30:00'), $switchWrite[1] ?? Actor::Human)];
        if (null !== $grant) {
            $values[self::GRANT] = $grant[0];
            $writes[self::GRANT] = new CardValueWrite(new \DateTimeImmutable($grant[1]), $grant[2]);
        }

        return FactsMother::facts(
            card: FactsMother::card(slot: 'tech-design', documents: $documents),
            run: FactsMother::run(activeWorkKinds: $activeKinds, activeWorkerKinds: $activeKinds),
            provided: [
                CardValueFacts::class => new CardValueFacts($values, $writes),
                ColumnArrivalFacts::class => new ColumnArrivalFacts(new \DateTimeImmutable(self::ARRIVED)),
                SessionFacts::class => new SessionFacts($session ? new \DateTimeImmutable('2026-10-22 10:20:00') : null, null),
                ...$provided,
            ],
        );
    }

    private static function approvedDocument(string $id, string $at, bool $byWorkflow): DocumentFacts
    {
        return new DocumentFacts(
            tags: ['product-design'],
            status: 'approved',
            id: $id,
            newestApprovalAt: new \DateTimeImmutable($at),
            newestApprovalByWorkflow: $byWorkflow,
        );
    }

    /** @return list<string> the id of every Lifecycle rule of the card slot that matches the facts */
    private function firing(Facts $facts): array
    {
        $ids = [];
        foreach ($this->lifecycle()->rulesFor($facts->slot) as $rule) {
            if (str_starts_with($rule->id, 'tech-design-session') && $rule->when->evaluate($facts)) {
                $ids[] = $rule->id;
            }
        }

        return $ids;
    }

    /** @return list<ActionCall> */
    private function actionsOf(string $id): array
    {
        return $this->rule($id)->then;
    }

    private function setValue(string $key, bool $value): ActionCall
    {
        return self::getContainer()->get(\App\Module\Workflow\Action\Actions::class)->call('set-value', ['key' => $key, 'value' => $value]);
    }

    private function rule(string $id): \App\Module\Workflow\Template\Rule
    {
        return array_find($this->lifecycle()->rulesFor('tech-design'), static fn ($rule): bool => $id === $rule->id) ?? throw new \LogicException($id);
    }

    private function lifecycle(): Template
    {
        return static::getContainer()->get(TemplateParser::class)->parse(static::getContainer()->get(ShippedTemplates::class)->source('lifecycle'));
    }
}
