<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\ChildActivityFactProvider;
use App\Module\Board\Workflow\ChildActivityFacts;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ChildActivityFactProviderTest extends KernelTestCase
{
    use CardStateFixtures;

    private Card $epic;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->epic = $this->stateCard($this->stateProject('child-activity'));
    }

    public function test_a_card_with_no_child_has_no_child_activity(): void
    {
        $this->recordHistory($this->epic, '2026-10-02 10:00:00');
        $this->openRun($this->epic);

        $facts = $this->build();

        self::assertNull($facts->newestEventAt);
        self::assertFalse($facts->runOpen);
    }

    public function test_the_newest_event_of_any_child_is_the_fact_and_the_own_events_do_not_count(): void
    {
        $one = $this->child();
        $two = $this->child();
        $this->recordHistory($this->epic, '2026-10-02 12:00:00');
        $this->recordHistory($one, '2026-10-02 09:00:00');
        $this->recordHistory($two, '2026-10-02 10:00:00');
        $this->recordHistory($one, '2026-10-02 08:00:00');

        $facts = $this->build();

        self::assertEquals(new \DateTimeImmutable('2026-10-02 10:00:00'), $facts->newestEventAt);
        self::assertFalse($facts->runOpen);
    }

    public function test_a_child_with_no_event_gives_no_time(): void
    {
        $this->child();
        $this->recordHistory($this->epic, '2026-10-02 12:00:00');

        self::assertNull($this->build()->newestEventAt);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function openStates(): iterable
    {
        foreach (WorkerRunState::openStates() as $state) {
            yield $state->value => [$state];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('openStates')]
    public function test_a_child_run_in_an_open_state_is_an_open_run(WorkerRunState $state): void
    {
        $child = $this->child();
        self::assertFalse($this->build()->runOpen);

        $this->openRun($child)->moveTo($state);
        $this->em()->flush();

        self::assertTrue($this->build()->runOpen);
    }

    public function test_a_closed_child_run_is_no_open_run(): void
    {
        $child = $this->child();
        $run = $this->openRun($child);
        self::assertTrue($this->build()->runOpen);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();

        self::assertFalse($this->build()->runOpen);
    }

    public function test_an_open_run_of_the_epic_alone_or_an_interactive_child_run_is_no_open_run(): void
    {
        $child = $this->child();
        $this->openRun($this->epic);
        $this->em()->persist(new WorkerRun(
            project: $child->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $child->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $child->number,
            workKind: 'interactive',
            state: WorkerRunState::Running,
            kind: WorkerRunKind::Interactive,
        ));
        $this->em()->flush();

        self::assertFalse($this->build()->runOpen);
        $this->openRun($child);
        self::assertTrue($this->build()->runOpen);
    }

    public function test_the_fingerprint_changes_with_the_time_and_with_the_open_run(): void
    {
        $child = $this->child();
        $provider = $this->provider();
        $empty = $provider->fingerprint($this->build());
        $this->recordHistory($child, '2026-10-02 10:00:00');
        $timed = $provider->fingerprint($this->build());
        $this->openRun($child);
        $running = $provider->fingerprint($this->build());

        self::assertSame([null, false], $empty);
        self::assertNotSame($empty, $timed);
        self::assertNotSame($timed, $running);
        self::assertSame($running, $provider->fingerprint($this->build()));
    }

    public function test_the_parents_of_cards_are_each_listed_once_and_a_card_with_no_parent_gives_none(): void
    {
        $one = $this->child();
        $two = $this->child();
        $other = $this->stateCard($this->epic->project);
        $otherChild = $this->child($other);
        $loose = $this->stateCard($this->epic->project);
        $cards = $this->service(CardRepository::class);

        self::assertEqualsCanonicalizing(
            [$this->id($this->epic), $this->id($other)],
            $cards->findParentIds([$this->id($one), $this->id($two), $this->id($otherChild), $this->id($loose)]),
        );
        self::assertSame([$this->id($this->epic)], $cards->findParentIds([$this->id($one), $this->id($loose)]));
        self::assertSame([], $cards->findParentIds([$this->id($loose), $this->id($this->epic)]));
        self::assertSame([], $cards->findParentIds([]));
    }

    public function test_any_open_run_of_cards_reads_only_the_given_cards_and_worker_runs(): void
    {
        $one = $this->child();
        $two = $this->child();
        $runs = $this->service(WorkerRunRepository::class);
        $this->openRun($two);
        $ids = static fn (Card ...$cards): array => array_values(array_map(static fn (Card $card): Uuid => $card->id ?? throw new \LogicException('A flushed card has an id.'), $cards));

        self::assertTrue($runs->anyOpenWorkerRunOfCards($ids($one, $two)));
        self::assertTrue($runs->anyOpenWorkerRunOfCards($ids($two)));
        self::assertFalse($runs->anyOpenWorkerRunOfCards($ids($one)));
        self::assertFalse($runs->anyOpenWorkerRunOfCards([]));
    }

    private function child(?Card $parent = null): Card
    {
        $child = $this->stateCard($this->epic->project);
        $child->parent = $parent ?? $this->epic;
        $this->em()->flush();

        return $child;
    }

    private function id(Card $card): string
    {
        return ($card->id ?? throw new \LogicException('A flushed card has an id.'))->toRfc4122();
    }

    private function build(): ChildActivityFacts
    {
        $facts = $this->provider()->build($this->epic->snapshot());
        self::assertInstanceOf(ChildActivityFacts::class, $facts);

        return $facts;
    }

    private function provider(): ChildActivityFactProvider
    {
        return $this->service(ChildActivityFactProvider::class);
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
