<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Controller\Api\BoardColumnResponse;
use App\Module\Board\Controller\Api\CardTypeResponse;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Workflow\Contract\CardTypeDefinition;
use App\Module\Workflow\Contract\CardTypes;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Shows columns and card types to a person who reads text, because a seeded label is a translation key. */
final readonly class BoardColumnPresenter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function column(BoardColumn $column): BoardColumnResponse
    {
        return BoardColumnResponse::fromColumn($column, $this->translator->trans($column->label));
    }

    /**
     * @param list<BoardColumn> $columns
     *
     * @return list<BoardColumnResponse>
     */
    public function columns(array $columns): array
    {
        return array_map($this->column(...), $columns);
    }

    /** @return list<CardTypeResponse> */
    public function types(CardTypes $types): array
    {
        return array_map(
            fn (CardTypeDefinition $type): CardTypeResponse => new CardTypeResponse($type->key, $this->translator->trans($type->label), $type->children, $type->lane),
            $types->all,
        );
    }
}
