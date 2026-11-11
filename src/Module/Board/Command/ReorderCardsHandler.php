<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardChangeAnnouncer;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Places a block of cards of one open column in one locked write.
 *
 * The call is all or nothing: every check runs under the project lock, before
 * a row changes. A block changes rank only, so it sends no CardMoved and writes
 * no history row. Each listed card is announced to the open boards, and a
 * Backlog block also redraws the epics whose deck shows the cards.
 */
final readonly class ReorderCardsHandler
{
    public const string LIST_EMPTY = 'board.card.error.order_list_empty';
    public const string CARD_REPEATED = 'board.card.error.order_card_repeated';
    public const string CARD_ELSEWHERE = 'board.card.error.order_card_elsewhere';
    public const string NEIGHBOUR_LISTED = 'board.card.error.order_neighbour_listed';

    public function __construct(
        private CardRepository $cards,
        private ProjectRepository $projects,
        private BoardColumnRepository $boardColumns,
        private EntityManagerInterface $em,
        private CardChangeAnnouncer $announcer,
        private EventDispatcherInterface $events,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors naming each card at fault */
    public function __invoke(ReorderCardsCommand $command): ReorderCardsView
    {
        if (ReorderPlacement::Top !== $command->placement && ReorderPlacement::Bottom !== $command->placement && null === $command->neighbourId) {
            throw new \LogicException('A placement before or after a card names that card.');
        }

        $outcome = $this->em->wrapInTransaction(function () use ($command): DomainErrors|array {
            $this->projects->lockForWrite($command->project);

            $column = $command->column;
            if (!\in_array($column, $this->boardColumns->findForProjectFresh($command->project), true)) {
                return new DomainErrors(['column' => UpdateCardHandler::COLUMN_GONE]);
            }
            if ($column->terminal) {
                return new DomainErrors(['column' => UpdateCardHandler::PLACE_TERMINAL], ['column' => ['%column%' => $column->slug]]);
            }

            $ranked = $this->cards->rankedIds($column);
            $listed = $command->cardIds;
            $refusal = $this->refusal($command, $ranked);
            if (null !== $refusal) {
                return $refusal;
            }

            $block = array_flip($listed);
            $others = array_values(array_filter($ranked, static fn (string $id): bool => !isset($block[$id])));
            $start = match ($command->placement) {
                ReorderPlacement::Top => 0,
                ReorderPlacement::Bottom => \count($others),
                ReorderPlacement::Before => (int) array_search($command->neighbourId, $others, true),
                ReorderPlacement::After => (int) array_search($command->neighbourId, $others, true) + 1,
            };
            $order = [...\array_slice($others, 0, $start), ...$listed, ...\array_slice($others, $start)];

            $this->cards->rankColumn($column, $order, $listed, new \DateTimeImmutable());
            $this->cards->refreshLoadedRanks($column, null);

            return [$order, $start];
        });

        if ($outcome instanceof DomainErrors) {
            throw $outcome;
        }
        [$order, $start] = $outcome;

        // After the commit, never inside it: the sink drains at kernel.terminate, so a
        // record written in the closure outlives a rollback.
        $projectId = $command->project->requireId();
        $this->announcer->updated($projectId, array_map(Uuid::fromString(...), $command->cardIds));
        if ($command->column->backlog) {
            foreach ($this->cards->findParentIds($command->cardIds) as $epicId) {
                $this->events->dispatch(new CardChanged($projectId, Uuid::fromString($epicId), CardChanged::UPDATED, false));
            }
        }
        $this->auditor->record(
            'board.cards_reordered',
            AuditOutcome::Success,
            [
                'projectId' => (string) $projectId,
                'columnId' => (string) $command->column->id,
                'columnSlug' => $command->column->slug,
                'cardCount' => \count($command->cardIds),
                'placement' => $command->placement->value,
                'neighbourId' => $command->neighbourId,
            ],
            new AuditSubject('column', (string) $command->column->id),
        );

        return $this->view($command, $order, $start);
    }

    /** @param list<string> $ranked the ids of the column, read under the lock */
    private function refusal(ReorderCardsCommand $command, array $ranked): ?DomainErrors
    {
        $listed = $command->cardIds;
        $repeated = array_keys(array_filter(array_count_values($listed), static fn (int $count): bool => $count > 1));
        $elsewhere = array_values(array_diff($listed, $ranked));
        $neighbour = $command->neighbourId;
        $neighbourField = ReorderPlacement::Before === $command->placement ? 'beforeCard' : 'afterCard';

        $errors = [];
        $parameters = [];
        if ([] === $listed) {
            $errors['cards'] = self::LIST_EMPTY;
        }
        if ([] !== $repeated) {
            $errors['cards.repeated'] = self::CARD_REPEATED;
        }
        if ([] !== $elsewhere) {
            $errors['cards.elsewhere'] = self::CARD_ELSEWHERE;
        }
        if (null !== $neighbour && \in_array($neighbour, $listed, true)) {
            $errors[$neighbourField] = self::NEIGHBOUR_LISTED;
        } elseif (null !== $neighbour && !\in_array($neighbour, $ranked, true)) {
            $errors[$neighbourField] = UpdateCardHandler::NEIGHBOUR_ELSEWHERE;
        }
        if ([] === $errors) {
            return null;
        }

        $described = $this->cards->describeCards($command->project, array_values(array_unique([...$repeated, ...$elsewhere, ...(null === $neighbour ? [] : [$neighbour])])));
        $name = static fn (string $id): string => isset($described[$id]) ? '#'.$described[$id]['number'] : $id;
        $label = $command->column->slug;
        $parameters['cards.repeated'] = ['%cards%' => implode(', ', array_map($name, $repeated))];
        $parameters['cards.elsewhere'] = ['%cards%' => implode(', ', array_map($name, $elsewhere)), '%column%' => $label];
        $parameters[$neighbourField] = ['%card%' => null === $neighbour ? '' : $name($neighbour), '%where%' => null !== $neighbour && isset($described[$neighbour]) ? 'the '.$described[$neighbour]['column'].' column' : 'no column of this board', '%column%' => $label];

        return new DomainErrors($errors, $parameters);
    }

    /**
     * @param list<string> $order the column from the top
     */
    private function view(ReorderCardsCommand $command, array $order, int $start): ReorderCardsView
    {
        $end = $start + \count($command->cardIds) - 1;
        $wanted = array_values(array_unique(array_filter(
            [$order[$start - 1] ?? null, ...$command->cardIds, $order[$end + 1] ?? null],
            static fn (?string $id): bool => null !== $id,
        )));
        $ids = array_map(Uuid::fromString(...), $wanted);
        $titles = $this->cards->findTitlesByIds($command->project, $ids);
        $numbers = $this->cards->findNumbersByIds($command->project, $ids);

        $rank = array_flip($order);
        $row = static fn (string $id): ReorderedCard => new ReorderedCard($numbers[$id] ?? 0, $titles[$id] ?? '', $rank[$id]);

        return new ReorderCardsView(
            isset($order[$start - 1]) && $start > 0 ? $row($order[$start - 1]) : null,
            array_map($row, $command->cardIds),
            isset($order[$end + 1]) ? $row($order[$end + 1]) : null,
        );
    }
}
