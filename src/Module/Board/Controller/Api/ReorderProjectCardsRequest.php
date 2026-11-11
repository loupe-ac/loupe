<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The block and where it goes. A missing field stays uninitialized: a missing
 * `column` or `cards` fails NotNull, and a missing place answers `place_missing`.
 * An explicit null fails the property type. Both answer 422.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['column', 'cards']])]
final class ReorderProjectCardsRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'The slug of an open column of the board.')]
    public string $column;

    /** @var list<mixed> */
    #[Assert\All(new Assert\Type(['string', 'integer']))]
    #[Assert\NotNull]
    #[OA\Property(description: 'The cards of the block from top to bottom. Name each card by its number or its id. A number may be sent as a JSON integer too.', type: 'array', items: new OA\Items(type: 'string'))]
    public array $cards;

    #[Assert\Choice(choices: ['top', 'bottom'])]
    #[OA\Property(description: 'Where the block goes: `top` or `bottom` of the column. Pass exactly one of `at`, `beforeCard` and `afterCard`.', enum: ['top', 'bottom'])]
    public string $at;

    #[OA\Property(description: 'The number or the id of a card of the column that is not in the list. The block lands just above it.', type: 'string')]
    public int|string $beforeCard;

    #[OA\Property(description: 'The number or the id of a card of the column that is not in the list. The block lands just below it.', type: 'string')]
    public int|string $afterCard;

    /**
     * The place fields the call names.
     *
     * @return list<string>
     */
    public function placed(): array
    {
        return array_values(array_filter(
            ['at', 'beforeCard', 'afterCard'],
            fn (string $field): bool => isset($this->{$field}),
        ));
    }
}
