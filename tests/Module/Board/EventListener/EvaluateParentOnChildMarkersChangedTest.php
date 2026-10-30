<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\EventListener\EvaluateParentOnChildMarkersChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\BoardCardMarkers;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Contract\MarkerKind;
use App\Module\Workflow\Contract\StoredMarker;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;

final class EvaluateParentOnChildMarkersChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    /** @var list<string> */
    private array $evaluated = [];
    private bool $on = true;
    private EntityManagerInterface $em;
    private CardRepository $cards;
    private BoardCardMarkers $markers;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $cards = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $cards);
        $this->cards = $cards;

        $test = $this;
        $trigger = new readonly class($test) implements CardEvaluations {
            public function __construct(
                private EvaluateParentOnChildMarkersChangedTest $test,
            ) {
            }

            #[\Override]
            public function forCards(array $cardIds): void
            {
                $this->test->record($cardIds);
            }

            #[\Override]
            public function forPersonWaiting(array $cardIds): void
            {
            }

            #[\Override]
            public function isOn(): bool
            {
                return $this->test->isOn();
            }
        };
        $events = new EventDispatcher();
        $events->addListener(CardChanged::class, new EvaluateParentOnChildMarkersChanged($trigger, $cards));
        $this->markers = new BoardCardMarkers($cards, $events);
    }

    /** @param list<string|Uuid> $cardIds */
    public function record(array $cardIds): void
    {
        foreach ($cardIds as $cardId) {
            $this->evaluated[] = (string) $cardId;
        }
    }

    public function isOn(): bool
    {
        return $this->on;
    }

    public function test_a_new_marker_set_on_a_child_queues_a_check_of_its_epic_and_nothing_else(): void
    {
        [$epic, $child] = $this->epicWithChild('marker-roll-up');

        $this->write($child, [self::stuck()]);
        self::assertSame([(string) $epic->id], $this->evaluated);

        $this->write($child, [self::stuck()]);
        self::assertSame([(string) $epic->id], $this->evaluated, 'the same set changes nothing, so it queues nothing');

        $this->write($child, []);
        self::assertSame([(string) $epic->id, (string) $epic->id], $this->evaluated, 'a cleared set, such as on a move to Done, queues the epic again');

        $this->write($epic, [self::stuck()]);
        self::assertCount(2, $this->evaluated, 'the epic has no parent, so its own write ends the chain');
    }

    public function test_an_engine_that_is_off_queues_nothing(): void
    {
        [, $child] = $this->epicWithChild('marker-roll-up-off');
        $this->on = false;

        $this->write($child, [self::stuck()]);

        self::assertSame([], $this->evaluated);
    }

    public function test_an_update_that_is_not_the_marker_pass_is_left_to_the_card_listener(): void
    {
        [, $child] = $this->epicWithChild('marker-roll-up-update');
        $events = new EventDispatcher();
        $trigger = $this->createMock(CardEvaluations::class);
        $trigger->expects($this->never())->method('forCards');
        $events->addListener(CardChanged::class, new EvaluateParentOnChildMarkersChanged($trigger, $this->cards));

        $events->dispatch(new CardChanged($child->project->requireId(), $child->id ?? throw new \LogicException('A stored card has an id.'), CardChanged::UPDATED, false));
    }

    /** @return array{Card, Card} */
    private function epicWithChild(string $slug): array
    {
        $project = $this->makeProject($slug);
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $epic = $create(new CreateCardCommand($project, 'Epic', '', 'epic'));
        $child = $create(new CreateCardCommand($project, 'Child', '', 'feature'));
        $child->parent = $epic;
        $this->em->flush();
        $this->evaluated = [];

        return [$epic, $child];
    }

    /** @param list<StoredMarker> $markers */
    private function write(Card $card, array $markers): void
    {
        $this->markers->write($card->project->requireId(), $card->id ?? throw new \LogicException('A stored card has an id.'), $markers, [], null);
    }

    private static function stuck(): StoredMarker
    {
        return new StoredMarker('paused', MarkerKind::Stuck, 'marker.paused', 'marker.paused.remedy', [], new \DateTimeImmutable('2026-10-18T09:00:00+00:00'));
    }
}
