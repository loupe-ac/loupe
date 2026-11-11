<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\ReorderCardsCommand;
use App\Module\Board\Command\ReorderCardsHandler;
use App\Module\Board\Command\ReorderedCard;
use App\Module\Board\Command\ReorderPlacement;
use App\Module\Board\Service\CardRefResolver;
use App\Module\Workflow\Contract\Actor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Places a block of cards of one column, in one call.
 *
 * @phpstan-type PlacedCard array{number: int, title: string, rank: int}
 */
#[McpTool(name: self::NAME, description: 'Place a block of cards inside one column of the project board, in one call. Pass column, the slug of an open column; board_columns lists the slugs. Pass cards, the cards of the block from top to bottom; name each card by its number or its id. Pass exactly one of at, beforeCard and afterCard to say where the block goes: at top puts it above every other card of the column, at bottom puts it below every other card, beforeCard and afterCard put it just above or just below a card of the column that is not in the list. The cards that are not in the list keep their order around the block. A list that names every card of the column reorders the whole column. The tool changes ranks only: every listed card must already sit in the column, and a card of another column refuses the call. To move a card into a column at a place, call card_update. A terminal column sorts by completion time and refuses the call. An empty list, a card listed twice, an unknown card and a neighbour that is in the list or not in the column refuse the call, the error names each card at fault, and no card moves. A rank change starts no workflow work and writes no history row. The result holds the placed cards and the card just above and just below the block, each with its number, title and new rank, top first.')]
final readonly class CardReorderTool
{
    public const string NAME = 'card_reorder';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private CardRefResolver $cardRefs,
        private ReorderCardsHandler $reorderCards,
        private BoardToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param string            $column     the slug of an open column; board_columns lists the slugs
     * @param array<int|string> $cards      the cards of the block from top to bottom, each by its number or its id
     * @param string|null       $at         top or bottom; pass exactly one of at, beforeCard and afterCard
     * @param int|string|null   $beforeCard the number or the id of a card of the column that is not in the list; the block lands just above it
     * @param int|string|null   $afterCard  the number or the id of a card of the column that is not in the list; the block lands just below it
     *
     * @return array{above: PlacedCard|null, cards: list<PlacedCard>, below: PlacedCard|null}
     */
    public function __invoke(string $column, #[Schema(items: CardRefResolver::SCHEMA)] array $cards, #[Schema(enum: ['top', 'bottom'])] ?string $at = null, int|string|null $beforeCard = null, int|string|null $afterCard = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $target = $this->subjects->requireColumn($project, $column, 'column');

            $placed = array_filter(['at' => $at, 'beforeCard' => $beforeCard, 'afterCard' => $afterCard], static fn (int|string|null $place): bool => null !== $place);
            if ([] === $placed) {
                throw new ToolCallException('Pass one of at, beforeCard and afterCard to say where the block goes.');
            }
            if (\count($placed) > 1) {
                throw new ToolCallException('Pass only one of at, beforeCard and afterCard.');
            }
            if (null !== $at && !\in_array($at, ['top', 'bottom'], true)) {
                throw new ToolCallException(\sprintf('Unknown at "%s". Use top or bottom.', $at));
            }

            $groups = ['cards' => array_values($cards)];
            foreach (['beforeCard' => $beforeCard, 'afterCard' => $afterCard] as $field => $reference) {
                if (null !== $reference) {
                    $groups[$field] = [$reference];
                }
            }
            $resolved = $this->cardRefs->resolve($project, $groups);
            $neighbour = ($resolved['beforeCard'][0] ?? $resolved['afterCard'][0] ?? null);
            $this->subjects->requireWritable([...$resolved['cards'], ...(null === $neighbour ? [] : [$neighbour])]);

            $view = ($this->reorderCards)(new ReorderCardsCommand(
                project: $project,
                column: $target,
                cardIds: array_map(static fn ($card): string => $card->id?->toRfc4122() ?? throw new \LogicException('A stored card has an id.'), $resolved['cards']),
                placement: match (true) {
                    null !== $beforeCard => ReorderPlacement::Before,
                    null !== $afterCard => ReorderPlacement::After,
                    'top' === $at => ReorderPlacement::Top,
                    default => ReorderPlacement::Bottom,
                },
                neighbourId: $neighbour?->id?->toRfc4122(),
                actor: Actor::Agent,
            ));

            $row = static fn (?ReorderedCard $card): ?array => null === $card ? null : ['number' => $card->number, 'title' => $card->title, 'rank' => $card->rank];

            return [
                'above' => $row($view->above),
                'cards' => array_map(static fn (ReorderedCard $card): array => ['number' => $card->number, 'title' => $card->title, 'rank' => $card->rank], $view->cards),
                'below' => $row($view->below),
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->sentencesForAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The cards could not be placed. The error has been logged.', previous: $e);
        }
    }
}
