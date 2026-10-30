<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Workflow\Condition;

use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Bridge\Workflow\Condition\ParentWorkActive;
use App\Module\Bridge\Workflow\Condition\RunFinishedWith;
use App\Module\Bridge\Workflow\Condition\RunLastRefusal;
use App\Module\Bridge\Workflow\Condition\RunWorkActive;
use App\Module\Bridge\Workflow\Condition\RunWorkerActive;
use App\Module\Bridge\Workflow\Condition\RunWorkOpen;
use App\Module\Bridge\Workflow\LatestWorkFacts;
use App\Module\Bridge\Workflow\ParentWorkFacts;
use App\Module\Bridge\Workflow\RefusalFacts;
use App\Module\Bridge\Workflow\WorkerRunFacts;
use App\Module\Bridge\Workflow\WorkRequestFacts;
use App\Module\Bridge\Workflow\WorkTakerFacts;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunConditionsTest extends TestCase
{
    private const array NOTHING_TO_BUILD = ['kind' => 'implement', 'reason' => 'nothing-to-build'];

    /** @param array<string, mixed> $params */
    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, array $params, Facts $facts, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, Facts, bool}> */
    public static function cases(): iterable
    {
        yield 'work active, any kind' => [new RunWorkActive(), [], self::withRun(activeWorkKinds: ['fix']), true];
        yield 'work active, any kind, none active' => [new RunWorkActive(), [], self::withRun(activeWorkKinds: []), false];
        yield 'work active, that kind' => [new RunWorkActive(), ['kind' => 'fix'], self::withRun(activeWorkKinds: ['implement', 'fix']), true];
        yield 'work active, other kind' => [new RunWorkActive(), ['kind' => 'fix'], self::withRun(activeWorkKinds: ['implement']), false];

        yield 'work active, origin rule, only a person started it' => [new RunWorkActive(), ['origin' => 'rule'], self::withOrigins(requests: new WorkRequestFacts(['resume'], [], [], [])), false];
        yield 'work active, origin rule, a rule started it' => [new RunWorkActive(), ['origin' => 'rule'], self::withOrigins(requests: new WorkRequestFacts(['resume', 'fix'], [], ['fix'], [])), true];
        yield 'work active, origin rule, that kind by a person' => [new RunWorkActive(), ['origin' => 'rule', 'kind' => 'resume'], self::withOrigins(requests: new WorkRequestFacts(['resume', 'fix'], [], ['fix'], [])), false];
        yield 'work active, no origin, only a person started it' => [new RunWorkActive(), [], self::withOrigins(requests: new WorkRequestFacts(['resume'], [], [], [])), true];

        yield 'worker active, origin rule, only a person started it' => [new RunWorkerActive(), ['origin' => 'rule'], self::withOrigins(workers: new WorkerRunFacts(['product-design'], [], [], [])), false];
        yield 'worker active, origin rule, a rule started it' => [new RunWorkerActive(), ['origin' => 'rule', 'kind' => 'fix'], self::withOrigins(workers: new WorkerRunFacts(['fix', 'resume'], [], ['fix'], [])), true];
        yield 'worker active, origin rule, that kind by a person' => [new RunWorkerActive(), ['origin' => 'rule', 'kind' => 'resume'], self::withOrigins(workers: new WorkerRunFacts(['fix', 'resume'], [], ['fix'], [])), false];
        yield 'worker active, no origin, only a person started it' => [new RunWorkerActive(), [], self::withOrigins(workers: new WorkerRunFacts(['product-design'], [], [], [])), true];

        yield 'work open, an open request' => [new RunWorkOpen(), [], self::withReadings(['implement' => WorkTakerState::Available]), true];
        yield 'work open, an open request nobody can take' => [new RunWorkOpen(), [], self::withReadings(['implement' => WorkTakerState::None]), true];
        yield 'work open, only a claimed request' => [new RunWorkOpen(), [], FactsMother::facts(run: FactsMother::run(activeWorkKinds: ['implement'])), false];
        yield 'work open, no request' => [new RunWorkOpen(), [], self::withReadings([]), false];

        yield 'worker active, any kind' => [new RunWorkerActive(), [], self::withWorkers(activeWorkerKinds: ['breakdown']), true];
        yield 'worker active, any kind, none open' => [new RunWorkerActive(), [], self::withWorkers(), false];
        yield 'worker active, that kind' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withWorkers(activeWorkerKinds: ['breakdown', 'fix']), true];
        yield 'worker active, other kind' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withWorkers(activeWorkerKinds: ['fix']), false];
        yield 'worker active, only the parent runs' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withWorkers(parentActiveKinds: ['breakdown']), false];
        yield 'worker active, only a work request is live' => [new RunWorkerActive(), ['kind' => 'breakdown'], self::withRun(activeWorkKinds: ['breakdown']), false];

        yield 'parent work active, any kind' => [new ParentWorkActive(), [], self::withWorkers(parentActiveKinds: ['breakdown']), true];
        yield 'parent work active, any kind, none open' => [new ParentWorkActive(), [], self::withWorkers(), false];
        yield 'parent work active, that kind' => [new ParentWorkActive(), ['kind' => 'breakdown'], self::withWorkers(parentActiveKinds: ['implement', 'breakdown']), true];
        yield 'parent work active, other kind' => [new ParentWorkActive(), ['kind' => 'breakdown'], self::withWorkers(parentActiveKinds: ['implement']), false];
        yield 'parent work active, only the card runs' => [new ParentWorkActive(), ['kind' => 'breakdown'], self::withWorkers(activeWorkerKinds: ['breakdown']), false];

        yield 'last refusal, same code' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: 'no-worker'), true];
        yield 'last refusal, other code' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: 'dirty-tree'), false];
        yield 'last refusal, none' => [new RunLastRefusal(), ['code' => 'no-worker'], self::withRun(lastRefusalCode: null), false];

        yield 'finished with, done of that kind with that reason' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Done, 'implement', 'nothing-to-build'), true];
        yield 'finished with, done of another kind' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Done, 'fix', 'nothing-to-build'), false];
        yield 'finished with, done with another reason' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Done, 'implement', 'done'), false];
        yield 'finished with, done with no reason' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Done, 'implement', null), false];
        yield 'finished with, refused with that kind and reason' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Refused, 'implement', 'nothing-to-build'), false];
        yield 'finished with, a newer request is open' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Open, 'implement', null), false];
        yield 'finished with, a newer request was cancelled' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(WorkRequestState::Cancelled, 'implement', null), false];
        yield 'finished with, no request' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, self::withLatest(null, null, null), false];
    }

    /**
     * @param array<string, mixed>          $params
     * @param array<string, string>         $expectedParameters
     * @param list<EngineFact|class-string> $expectedReads
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for_and_what_it_reads(Condition $condition, array $params, string $expectedKey, array $expectedParameters, array $expectedReads): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame('workflow.waiting.'.$expectedKey, $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());

        $negated = $condition->waitingFor($params, negated: true);

        self::assertSame('workflow.waiting.not.'.$expectedKey, $negated->getMessage());
        self::assertSame($expectedParameters, $negated->getParameters());
        self::assertSame($expectedReads, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, string, array<string, string>, list<EngineFact|class-string>}> */
    public static function waiting(): iterable
    {
        yield 'card.run.work_active' => [new RunWorkActive(), ['kind' => 'fix'], 'run_work_active', [], [WorkRequestFacts::class]];
        yield 'card.run.work_open' => [new RunWorkOpen(), [], 'run_work_open', [], [WorkTakerFacts::class]];
        yield 'card.run.worker_active' => [new RunWorkerActive(), ['kind' => 'breakdown'], 'run_worker_active', [], [WorkerRunFacts::class]];
        yield 'card.parent.run.active' => [new ParentWorkActive(), ['kind' => 'breakdown'], 'parent_work_active', [], [ParentWorkFacts::class]];
        yield 'card.run.last_refusal' => [new RunLastRefusal(), ['code' => 'no-worker'], 'run_last_refusal', ['%code%' => 'no-worker'], [RefusalFacts::class]];
        yield 'card.run.finished_with' => [new RunFinishedWith(), self::NOTHING_TO_BUILD, 'run_finished_with', ['%kind%' => 'implement', '%reason%' => 'nothing-to-build'], [LatestWorkFacts::class]];
    }

    private static function withOrigins(?WorkRequestFacts $requests = null, ?WorkerRunFacts $workers = null): Facts
    {
        return FactsMother::facts(provided: array_filter([WorkRequestFacts::class => $requests, WorkerRunFacts::class => $workers]));
    }

    /** @param list<string> $activeWorkKinds */
    private static function withRun(array $activeWorkKinds = [], ?string $lastRefusalCode = null): Facts
    {
        return FactsMother::facts(run: FactsMother::run(activeWorkKinds: $activeWorkKinds, lastRefusalCode: $lastRefusalCode));
    }

    /** @param array<string, WorkTakerState> $states */
    private static function withReadings(array $states): Facts
    {
        $readings = array_map(static fn (WorkTakerState $state): WorkTakerReading => new WorkTakerReading($state, new \DateTimeImmutable('2026-10-01 11:00:00'), null), $states);

        return FactsMother::facts(run: FactsMother::run(activeWorkKinds: array_keys($states)), provided: [WorkTakerFacts::class => new WorkTakerFacts($readings)]);
    }

    private static function withLatest(?WorkRequestState $state, ?string $kind, ?string $reason): Facts
    {
        return FactsMother::facts(run: FactsMother::run(latestWorkState: $state, latestWorkKind: $kind, latestWorkReason: $reason));
    }

    /**
     * @param list<string> $activeWorkerKinds
     * @param list<string> $parentActiveKinds
     */
    private static function withWorkers(array $activeWorkerKinds = [], array $parentActiveKinds = []): Facts
    {
        return FactsMother::facts(run: FactsMother::run(activeWorkerKinds: $activeWorkerKinds, parentActiveKinds: $parentActiveKinds));
    }
}
