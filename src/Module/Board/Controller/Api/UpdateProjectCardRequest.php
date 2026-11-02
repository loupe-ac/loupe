<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\ChildDesignChoices;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A partial change of a card. A field left out stays uninitialized and keeps its
 * value. An explicit null fails the property type, so it answers 422. The reporter
 * and the number are absent on purpose: they never change.
 */
#[OA\Schema(description: 'A field you leave out keeps its value. Reporter and number cannot change.', x: [PartialChangeSchemaProcessor::MARKER => true])]
final class UpdateProjectCardRequest
{
    #[Assert\Length(max: Card::MAX_TITLE_LENGTH)]
    #[OA\Property(maxLength: Card::MAX_TITLE_LENGTH)]
    public string $title;

    #[OA\Property(description: 'Markdown. Write another card as `card:<number>` and a pull request as `pr:owner/repo#<number>`.')]
    public string $body;

    #[OA\Property(description: 'A type key from the column list.')]
    public string $type;

    #[OA\Property(description: 'The slug of a column on this board. A change appends the card to the end of the column.')]
    public string $status;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'The full set. Leave it out to keep the links, and send an empty list to remove them all.', type: 'array', items: new OA\Items(type: 'string'))]
    public array $pullRequestUrls;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'The full set, as `pullRequestUrls` says.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $documentIds;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'The full set, as `pullRequestUrls` says.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $artifactIds;

    /** @var list<RelatedCardInput> */
    #[Assert\Valid]
    #[OA\Property(description: 'The full set. It replaces every link that touches the card, including links written from the other card.', type: 'array', items: new OA\Items(ref: new Model(type: RelatedCardInput::class)))]
    public array $relatedCards;

    #[OA\Property(description: 'The id of the new parent. Leave it out to keep the parent. An empty string clears it.')]
    public string $parentCardId;

    #[OA\Property(description: 'True removes the card from its parent. Do not pass it with `parentCardId`.')]
    public bool $clearParent;

    #[OA\Property(description: 'Whether the board draws a lane for the card.')]
    public bool $laneEnabled;

    #[Assert\Choice(choices: ChildDesignChoices::CHOICES)]
    #[OA\Property(description: 'As in card creation. Pass no `status` with `own`.', enum: ChildDesignChoices::CHOICES)]
    public string $childDesign;

    #[OA\Property(description: 'A bridge worker can only turn it on.')]
    public bool $requiresHumanReview;

    /** @var array<string, mixed> */
    #[OA\Property(description: 'Maps a declared key to its value, such as `{"tech-design-interactive": true}`. A key you leave out keeps its value. One bad entry refuses the whole call.', type: 'object', additionalProperties: true)]
    public array $values;
}
