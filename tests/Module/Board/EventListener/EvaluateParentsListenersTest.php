<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\RecordPullRequestSyncCommand;
use App\Module\Board\Command\RecordPullRequestSyncHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\EventListener\EvaluateParentsOnCardEventWritten;
use App\Module\Board\EventListener\EvaluateParentsOnWorkerRunChanged;
use App\Module\Board\EventListener\WriteCardEventOnRunFinished;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Tests\Module\Board\CardStateFixtures;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EvaluateParentsListenersTest extends KernelTestCase
{
    use CardStateFixtures;

    private Project $project;

    private Card $epic;

    private Card $child;

    private Card $loose;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->stateProject('evaluate-parents');
        $this->epic = $this->stateCard($this->project);
        $this->child = $this->stateCard($this->project);
        $this->loose = $this->stateCard($this->project);
        $this->child->parent = $this->epic;
        $this->em()->flush();
        $this->transport()->reset();
    }

    public function test_a_run_change_of_a_child_asks_for_its_epic(): void
    {
        $this->listener()(new WorkerRunChanged($this->project->id ?? throw new \LogicException('A flushed project has an id.'), [$this->id($this->child)], []));

        self::assertSame([$this->id($this->epic)], $this->sent());
    }

    public function test_a_run_change_of_a_card_with_no_parent_asks_for_nothing(): void
    {
        $listener = $this->listener();
        $projectId = $this->project->id ?? throw new \LogicException('A flushed project has an id.');

        $listener(new WorkerRunChanged($projectId, [$this->id($this->child)], []));
        self::assertNotSame([], $this->sent());

        $this->transport()->reset();
        $listener(new WorkerRunChanged($projectId, [$this->id($this->loose), $this->id($this->epic)], []));
        self::assertSame([], $this->sent());
    }

    public function test_the_run_listener_runs_after_the_one_that_writes_the_history_row(): void
    {
        self::assertSame(0, self::priority(WriteCardEventOnRunFinished::class));
        self::assertLessThan(self::priority(WriteCardEventOnRunFinished::class), self::priority(EvaluateParentsOnWorkerRunChanged::class));
        self::assertLessThan(0, self::priority(EvaluateParentsOnWorkerRunChanged::class));
    }

    /** @param class-string $class */
    private static function priority(string $class): int
    {
        $attributes = new \ReflectionClass($class)->getAttributes(AsEventListener::class);
        self::assertCount(1, $attributes);

        return $attributes[0]->newInstance()->priority;
    }

    public function test_a_history_row_of_a_child_asks_for_its_epic_after_the_flush(): void
    {
        $this->service(CardEventRepository::class)->record($this->child, CardEventKind::Moved, Actor::Agent, null, []);

        self::assertSame([], $this->sent(), 'The row is not flushed yet.');
        $this->em()->flush();

        self::assertSame([$this->id($this->epic)], $this->sent());
        self::assertSame([], $this->collector()->cardIds);
    }

    public function test_a_history_row_of_a_card_with_no_parent_asks_for_nothing(): void
    {
        $this->service(CardEventRepository::class)->record($this->child, CardEventKind::Moved, Actor::Agent, null, []);
        $this->em()->flush();
        self::assertNotSame([], $this->sent());

        $this->transport()->reset();
        $this->service(CardEventRepository::class)->record($this->loose, CardEventKind::Moved, Actor::Agent, null, []);
        $this->service(CardEventRepository::class)->record($this->epic, CardEventKind::Moved, Actor::Agent, null, []);
        $this->em()->flush();

        self::assertSame([], $this->sent());
    }

    public function test_a_pull_request_sync_of_a_child_asks_for_its_epic(): void
    {
        $childPullRequest = $this->linkPullRequest($this->child, []);
        $loosePullRequest = $this->linkPullRequest($this->loose, []);
        $sync = $this->service(RecordPullRequestSyncHandler::class);
        $this->transport()->reset();

        $sync(new RecordPullRequestSyncCommand($childPullRequest));
        self::assertContains($this->id($this->epic), $this->sent());

        $this->transport()->reset();
        $sync(new RecordPullRequestSyncCommand($loosePullRequest));
        self::assertNotContains($this->id($this->epic), $this->sent());
    }

    public function test_the_collector_collects_the_card_of_a_new_row_and_clear_and_reset_drop_it(): void
    {
        $collector = $this->collector();
        $records = $this->service(CardEventRepository::class);
        $records->record($this->child, CardEventKind::Moved, Actor::Agent, null, []);
        $this->em()->getUnitOfWork()->computeChangeSets();
        $collector->onFlush(new OnFlushEventArgs($this->em()));
        self::assertSame([$this->id($this->child) => true], $collector->cardIds);

        $collector->onClear();
        self::assertSame([], $collector->cardIds);

        $collector->onFlush(new OnFlushEventArgs($this->em()));
        self::assertNotSame([], $collector->cardIds);
        $collector->reset();
        self::assertSame([], $collector->cardIds);

        $collector->postFlush();
        self::assertSame([], $this->sent());
    }

    private function listener(): EvaluateParentsOnWorkerRunChanged
    {
        return new EvaluateParentsOnWorkerRunChanged($this->service(CardRepository::class), $this->service(EvaluationTrigger::class));
    }

    private function collector(): EvaluateParentsOnCardEventWritten
    {
        return $this->service(EvaluateParentsOnCardEventWritten::class);
    }

    private function id(Card $card): string
    {
        return ($card->id ?? throw new \LogicException('A flushed card has an id.'))->toRfc4122();
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
        $ids = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
