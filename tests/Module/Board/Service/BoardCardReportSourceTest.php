<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Service\BoardCardReportSource;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BoardCardReportSourceTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardReportSourceInterface $source;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $source = self::getContainer()->get(CardReportSourceInterface::class);
        self::assertInstanceOf(BoardCardReportSource::class, $source);
        $this->source = $source;
    }

    public function test_it_returns_the_columns_of_the_projects_cards_only(): void
    {
        $project = $this->makeProject('card-report');
        $other = $this->makeProject('card-report-other');
        $open = $this->card($project, 1, 'in-progress');
        $finished = $this->card($project, 2, 'done');
        $foreign = $this->card($other, 1, 'done');
        $this->em->clear();

        $columns = $this->source->columnsFor($project, [$open, $finished, $foreign, Uuid::v7()]);

        $expected = [
            (string) $open => new CardColumn('board.card.status.in-progress', false),
            (string) $finished => new CardColumn('board.card.status.done', true),
        ];
        ksort($expected);
        ksort($columns);
        self::assertEquals($expected, $columns);
    }

    public function test_it_returns_nothing_for_no_ids(): void
    {
        self::assertSame([], $this->source->columnsFor($this->makeProject('card-report-empty'), []));
    }

    public function test_it_reads_the_set_of_outcomes_that_the_moves_of_each_card_set(): void
    {
        $project = $this->makeProject('card-outcome');
        $other = $this->makeProject('card-outcome-other');
        $both = $this->card($project, 1, 'done');
        $handMoved = $this->card($project, 2, 'done');
        $quiet = $this->card($project, 3, 'in-progress');
        $same = $this->card($project, 4, 'done');
        $legacy = $this->card($project, 5, 'done');
        $foreign = $this->card($other, 1, 'done');

        $first = new \DateTimeImmutable('2026-09-01 09:00:00');
        $rule = static fn (string $id, ?string $outcome = null): array => ['from' => [], 'to' => [], 'cause' => ['type' => 'workflow-rule', 'rule' => $id] + (null === $outcome ? [] : ['outcome' => $outcome])];
        $this->event($both, CardEventKind::Moved, $rule('card-abandoned', 'abandoned'), $first);
        $this->event($both, CardEventKind::Moved, $rule('card-merged', 'merged'), $first->modify('+1 day'));
        $this->event($both, CardEventKind::FixRequested, ['reason' => 'conflict', 'outcome' => 'ignored'], $first->modify('+2 days'));
        $this->event($handMoved, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => null], $first);
        $this->event($quiet, CardEventKind::Moved, $rule('reviewable'), $first);
        $this->event($same, CardEventKind::Moved, $rule('card-merged', 'merged'), $first);
        $this->event($same, CardEventKind::Moved, $rule('card-merged', 'merged'), $first->modify('+1 day'));
        $this->event($legacy, CardEventKind::Moved, ['from' => [], 'to' => [], 'cause' => ['type' => 'merged', 'pullRequest' => 3]], $first);
        $this->event($foreign, CardEventKind::Moved, $rule('card-merged', 'merged'), $first);
        $this->em->clear();

        $outcomes = $this->source->outcomesOfCards($project, [$both, $handMoved, $quiet, $same, $legacy, $foreign, Uuid::v7()]);

        $expected = [(string) $both => ['abandoned', 'merged'], (string) $same => ['merged']];
        ksort($expected);
        ksort($outcomes);
        self::assertSame($expected, $outcomes);
        self::assertSame([], $this->source->outcomesOfCards($project, []));
    }

    public function test_it_returns_the_types_of_the_projects_cards_only(): void
    {
        $project = $this->makeProject('card-types');
        $other = $this->makeProject('card-types-other');
        $feature = $this->card($project, 1, 'done');
        $bug = $this->card($project, 2, 'done', 'bug');
        $foreign = $this->card($other, 1, 'done', 'docs');
        $this->em->clear();

        $types = $this->source->typesFor($project, [$feature, $bug, $foreign, Uuid::v7()]);

        ksort($types);
        $expected = [(string) $feature => 'feature', (string) $bug => 'bug'];
        ksort($expected);
        self::assertSame($expected, $types);
        self::assertSame([], $this->source->typesFor($project, []));
    }

    public function test_the_history_starts_at_the_first_event_of_the_project(): void
    {
        $project = $this->makeProject('history-start');
        $other = $this->makeProject('history-start-other');
        self::assertNull($this->source->historyStartFor($project));

        $card = $this->card($project, 1, 'in-progress');
        $this->event($card, CardEventKind::Moved, [], new \DateTimeImmutable('2026-09-03 08:00:00'));
        $this->event($card, CardEventKind::Created, [], new \DateTimeImmutable('2026-09-02 08:00:00'));
        $this->event($this->card($other, 1, 'in-progress'), CardEventKind::Created, [], new \DateTimeImmutable('2026-01-01 08:00:00'));
        $this->em->clear();

        self::assertEquals(new \DateTimeImmutable('2026-09-02 08:00:00'), $this->source->historyStartFor($project));
    }

    /** @param array<string, mixed> $detail */
    private function event(Uuid $cardId, CardEventKind $kind, array $detail, \DateTimeImmutable $at): void
    {
        $card = $this->em->find(Card::class, $cardId) ?? throw new \LogicException('The card exists.');
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $events->record($card, $kind, Actor::System, null, $detail, $at);
        $this->em->flush();
    }

    private function card(Project $project, int $number, string $column, string $type = 'feature'): Uuid
    {
        $card = new Card(project: $project, column: $this->column($project, $column), title: 'Card '.$number, body: '', number: $number, type: $type);
        $this->em->persist($card);
        $this->em->flush();

        return $card->id ?? throw new \LogicException('The card has no id after a flush.');
    }
}
