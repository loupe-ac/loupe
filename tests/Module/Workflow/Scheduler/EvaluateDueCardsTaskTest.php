<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Scheduler;

use App\Module\Board\Entity\Card;
use App\Module\Workflow\Command\EvaluateDueWorkflowCardsHandler;
use App\Module\Workflow\Contract\CardsDueForMarkers;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Scheduler\EvaluateDueCardsTask;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EvaluateDueCardsTaskTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_task_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame('* * * * *', ScheduledTasks::cronExpressions(self::getContainer())[EvaluateDueCardsTask::class] ?? null);
    }

    public function test_the_task_queues_an_evaluation_of_each_card_with_a_due_retry(): void
    {
        [$due, $later] = $this->scenario();

        $this->task()();

        $sent = $this->sent();
        self::assertContains((string) $due->id, $sent);
        self::assertNotContains((string) $later->id, $sent);
    }

    public function test_the_task_queues_the_open_cards_of_bound_projects_whose_inbox_markers_are_missing_or_whose_markers_are_due(): void
    {
        self::bootKernel();
        $bound = $this->workflowProject('due-markers');
        $this->bindLifecycle($bound);
        $missing = $this->card($bound, 'next');
        $due = $this->card($bound, 'next');
        $later = $this->card($bound, 'next');
        $settled = $this->card($bound, 'next');
        $finished = $this->card($bound, 'done');
        $unbound = $this->card($this->workflowProject('due-markers-unbound'), 'next');
        $due->markers = [];
        $due->inboxMarkers = [];
        $due->markersDueAt = new \DateTimeImmutable('2026-10-02 11:59:00');
        $later->markers = [];
        $later->inboxMarkers = [];
        $later->markersDueAt = new \DateTimeImmutable('2026-10-02 12:01:00');
        $settled->markers = [];
        $settled->inboxMarkers = [];
        $this->em()->flush();
        $this->transport()->reset();

        $this->task()();

        $sent = $this->sent();
        sort($sent);
        $expected = [(string) $missing->id, (string) $due->id];
        sort($expected);
        self::assertSame($expected, $sent);
        self::assertNotContains((string) $later->id, $sent);
        self::assertNotContains((string) $settled->id, $sent);
        self::assertNotContains((string) $finished->id, $sent);
        self::assertNotContains((string) $unbound->id, $sent);
    }

    /** @return array{Card, Card} */
    private function scenario(): array
    {
        self::bootKernel();
        $project = $this->workflowProject('due-cards');
        $due = $this->card($project, 'next');
        $later = $this->card($project, 'next');
        // Cards with stored markers, so only the due retry decides.
        $due->markers = [];
        $later->markers = [];
        foreach ([[$due, '2026-10-02 11:59:00'], [$later, '2026-10-02 12:01:00']] as [$card, $dueAt]) {
            $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $project, 'rule');
            $state->dueAt = new \DateTimeImmutable($dueAt);
            $this->em()->persist($state);
        }
        $this->em()->flush();
        $this->transport()->reset();

        return [$due, $later];
    }

    private function task(): EvaluateDueCardsTask
    {
        return new EvaluateDueCardsTask(new EvaluateDueWorkflowCardsHandler(
            $this->service(WorkflowRuleStateRepository::class),
            $this->service(CardsDueForMarkers::class),
            $this->service(EvaluationTrigger::class),
            new MockClock('2026-10-02 12:00:00'),
        ));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.card_eval');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> */
    private function sent(): array
    {
        return array_values(array_map(
            static fn (EvaluateCard $message): string => $message->cardId,
            array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard),
        ));
    }
}
