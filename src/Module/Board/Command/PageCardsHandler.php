<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Problem\ApiProblemException;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Component\Uid\Uuid;

/**
 * Reads one keyset page of a project's cards, filtered by search words, column, type,
 * reporter, parent and pause. The walk order is the card number or the update time,
 * so a card that moves between columns never moves a walk.
 */
final readonly class PageCardsHandler
{
    private const string TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private CardTypeCatalog $catalog,
    ) {
    }

    /** @throws ApiProblemException `validation_failed` for a filter that names nothing, `invalid_cursor` for a cursor that does not parse */
    public function __invoke(PageCardsCommand $command): PageCardsView
    {
        $project = $command->project;
        $errors = [];

        $column = null;
        if (null !== $command->status) {
            $column = $this->boardColumns->findOneByProjectAndSlug($project, $command->status);
            if (null === $column) {
                $errors[] = ['field' => 'status', 'code' => 'unknown_status', 'message' => \sprintf('This board has no column "%s".', $command->status)];
            }
        }
        if (null !== $command->type && !$this->catalog->forProject($project->requireId())->has($command->type)) {
            $errors[] = ['field' => 'type', 'code' => 'unknown_type', 'message' => \sprintf('This project declares no card type "%s".', $command->type)];
        }
        $reporter = null === $command->reporter ? null : Actor::tryFrom($command->reporter);
        if (null !== $command->reporter && null === $reporter) {
            $errors[] = ['field' => 'reporter', 'code' => 'reporter_invalid', 'message' => \sprintf('Use one of: %s.', implode(', ', Actor::values()))];
        }
        $parent = null;
        if (null !== $command->parentCardId || null !== $command->parentNumber) {
            if (null !== $command->parentCardId && null !== $command->parentNumber) {
                $errors[] = ['field' => 'parent', 'code' => 'parent_conflict', 'message' => 'Pass parentCardId or parent, not both.'];
            } else {
                $parent = null === $command->parentNumber
                    ? $this->cards->findOneByIdAndProjectId($command->parentCardId ?? '', (string) $project->id)
                    : $this->cards->findOneByProjectAndNumber($project, $command->parentNumber);
                if (null === $parent) {
                    $errors[] = ['field' => null === $command->parentNumber ? 'parentCardId' : 'parent', 'code' => 'parent_unknown', 'message' => 'That parent names no card of this project.'];
                }
            }
        }
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        $rows = $this->cards->findPageForProject(
            $project,
            $command->sort->field(),
            $command->sort->descending(),
            $command->page->fetchSize(),
            null === $command->page->cursor ? null : self::positionOf($command->page->cursor, $command->sort->field()),
            $column,
            $command->type,
            $reporter,
            $parent,
            $command->paused,
            $command->query,
        );

        return new PageCardsView(Page::fromRows($rows, $command->page, static fn (Card $card): Cursor => new Cursor(
            $command->sort->value,
            'number' === $command->sort->field() ? (string) $card->number : $card->updatedAt->format(self::TIME_FORMAT),
            (string) $card->id,
            $command->filters,
        )));
    }

    /** @return array{string, Uuid} */
    private static function positionOf(Cursor $cursor, string $sort): array
    {
        $valid = 'number' === $sort
            ? false !== filter_var($cursor->sortValue, \FILTER_VALIDATE_INT)
            : false !== \DateTimeImmutable::createFromFormat('!'.self::TIME_FORMAT, $cursor->sortValue);
        if (!$valid || !Uuid::isValid($cursor->id)) {
            throw ApiProblemException::invalidCursor('The cursor is broken. Start the list again without it.');
        }

        return [$cursor->sortValue, Uuid::fromString($cursor->id)];
    }
}
