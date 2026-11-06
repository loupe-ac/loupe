<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\InboxLimits;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A new inbox item. A field left out takes its default, and an explicit null fails
 * the property type and answers 422. The handler checks the rest, as it does for an agent.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['kind', 'title']])]
final class CreateInboxItemRequest
{
    public const array KINDS = ['question', 'todo', 'notice'];

    #[Assert\Choice(choices: self::KINDS)]
    #[Assert\NotNull]
    #[OA\Property(description: 'A `question` takes options, freeText or both. A `todo` is marked done or declined. A `notice` only informs, and the owner dismisses it.', nullable: false, enum: self::KINDS)]
    public ?string $kind = null;

    #[Assert\NotNull]
    #[OA\Property(description: 'One line.', nullable: false, maxLength: InboxItem::MAX_TITLE_LENGTH)]
    public ?string $title = null;

    #[OA\Property(description: 'Markdown. Write a card as `card:<number>` and a pull request as `pr:owner/repo#<number>`.', maxLength: InboxLimits::MAX_BODY_LENGTH)]
    public string $body;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'The answers the owner picks from. A question only.', type: 'array', items: new OA\Items(type: 'string', maxLength: InboxLimits::MAX_OPTION_LENGTH), maxItems: InboxLimits::MAX_OPTIONS)]
    public array $options;

    #[OA\Property(description: 'Whether the owner may pick several options. It needs at least two options.')]
    public bool $multiple;

    #[OA\Property(description: 'Whether the owner may write an answer.')]
    public bool $freeText;

    #[Assert\IsFalse(message: 'inbox.api.error.blocking_refused')]
    #[OA\Property(description: 'Always false. Nothing waits on an item from the API, so `true` is refused.', enum: [false])]
    public bool $blocking;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'Cards of this project. Any other id is refused.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: InboxLimits::MAX_LINKS)]
    public array $cardIds;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'Documents of this project. Any other id is refused.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: InboxLimits::MAX_LINKS)]
    public array $documentIds;

    #[Assert\Length(max: InboxItem::MAX_DEDUPE_KEY_LENGTH)]
    #[OA\Property(description: 'A key of your choice. While an open item of the project holds the same key, the call returns that item with 200 and creates nothing. Once that item closes, the next call creates a new item. Blank counts as no key.', maxLength: InboxItem::MAX_DEDUPE_KEY_LENGTH)]
    public string $dedupeKey;

    /** The trimmed key, or null when it is absent or blank. */
    public function dedupeKey(): ?string
    {
        $key = trim($this->dedupeKey ?? '');

        return '' === $key ? null : $key;
    }
}
