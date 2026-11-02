<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Entity\CardLinkKind;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** One link a card write asks for: the other card, and how the written card reads it. */
#[OA\Schema(required: ['cardId'])]
final class RelatedCardInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(description: 'The id of a card of this project.', format: 'uuid', nullable: false)]
    public ?string $cardId = null;

    #[Assert\Choice(callback: [self::class, 'kinds'])]
    #[OA\Property(description: 'How this card reads the other card.', default: 'relates-to', enum: ['relates-to', 'blocks', 'blocked-by', 'has-follow-up', 'follow-up-of'])]
    public string $kind = 'relates-to';

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_map(static fn (CardLinkKind $kind): string => $kind->value, CardLinkKind::cases());
    }

    public function toLink(): CardLinkInput
    {
        return new CardLinkInput($this->cardId ?? throw new \LogicException('cardId required after validation'), CardLinkKind::from($this->kind));
    }
}
