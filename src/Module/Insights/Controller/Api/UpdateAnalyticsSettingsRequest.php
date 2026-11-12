<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A partial change of the analytics settings. A field the body leaves out keeps its value, and the
 * controller reads the names in the body to tell a field left out from an explicit null. An explicit
 * null clears `defaultModel` and `defaultEffort`. The handler checks every value, so a refusal
 * reaches the client as one list.
 */
#[OA\Schema(description: 'A field you leave out keeps its value.', x: [PartialChangeSchemaProcessor::MARKER => true])]
final class UpdateAnalyticsSettingsRequest
{
    #[OA\Property(description: 'The model a new analysis takes when it names none, one word such as `sonnet` or `opus`. Null clears the project value, so the instance default applies.', nullable: true, maxLength: 64)]
    public ?string $defaultModel = null;

    #[OA\Property(description: '`low`, `medium`, `high`, `xhigh` or `max`. Null clears the project value, so the instance default applies.', nullable: true)]
    public ?string $defaultEffort = null;

    #[OA\Property(description: 'Whether the bridge sends the full text of each tool call.')]
    public bool $collectFullText;

    /** @var list<string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: 'The programs whose second word joins the signature of a shell command, such as `git` or `npm`. At most 50 names of letters, digits and `. _ + -`. Replaces the list, and an empty list clears it, so the instance list applies.', type: 'array', items: new OA\Items(type: 'string'), maxItems: 50)]
    public array $subcommandPrograms;
}
