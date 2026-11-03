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
 * A new card. A field left out takes its default, and an explicit null fails the
 * property type and answers 422. A missing title, body or type fails NotBlank or
 * NotNull, so those types stay nullable and the schema does not.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['title', 'body', 'type']])]
final class CreateProjectCardRequest
{
    #[Assert\Length(max: Card::MAX_TITLE_LENGTH)]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(nullable: false, maxLength: Card::MAX_TITLE_LENGTH)]
    public ?string $title = null;

    #[Assert\NotNull]
    #[OA\Property(description: 'Markdown. Write another card as `card:<number>` and a pull request as `pr:owner/repo#<number>`.', nullable: false)]
    public ?string $body = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[OA\Property(description: 'A type key from the column list. A key the project does not declare is refused.', nullable: false)]
    public ?string $type = null;

    #[OA\Property(description: 'The slug of the column the card lands in. It defaults to backlog.')]
    public string $status;

    #[Assert\Choice(choices: ['human', 'agent', 'api-key'])]
    #[OA\Property(description: 'Who raised the card. An API key may pass `human` or `api-key`, and defaults to `api-key`. A CLI sign-in may pass `human` or `agent`, and defaults to `agent`. Any other pair is refused.', enum: ['human', 'agent', 'api-key'])]
    public string $reporter;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'A URL from an unrecognised forge is kept as given.', type: 'array', items: new OA\Items(type: 'string'))]
    public array $pullRequestUrls;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'Documents of this project. Any other id is refused.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $documentIds;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'Artifacts of this project. Any other id is refused.', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $artifactIds;

    /** @var list<RelatedCardInput> */
    #[Assert\Valid]
    #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: RelatedCardInput::class)))]
    public array $relatedCards;

    #[OA\Property(description: 'A card of this project whose type has the children capability.', format: 'uuid')]
    public string $parentCardId;

    #[OA\Property(description: 'Whether the board draws a lane for the card. It defaults to true.')]
    public bool $laneEnabled;

    #[Assert\Choice(choices: ChildDesignChoices::CHOICES)]
    #[OA\Property(description: 'Whether the tech design of the parent covers the card, for a card under a parent whose tech design is approved. Pass no `status` with `own`.', enum: ChildDesignChoices::CHOICES)]
    public string $childDesign;

    #[OA\Property(description: 'True when a person must review the pull request. It defaults to false.')]
    public bool $requiresHumanReview;
}
