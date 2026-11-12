<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Inbox\Form\RunInboxActionRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One action to run on an inbox item. A field left out takes its default, and an explicit null
 * fails the property type and answers 422. The handlers check the rest, as they do for the owner.
 */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['action']])]
final class RunInboxItemActionRequest
{
    public const array ACTIONS = ['answer', 'complete', 'dismiss', 'resume-run', 'retry-card', 'send-agent', 'start-session', 'run-command', 'add-entry'];

    #[Assert\Choice(choices: self::ACTIONS)]
    #[Assert\NotNull]
    #[OA\Property(description: '`answer` responds to a question, `complete` marks a to-do done and `dismiss` declines an item that allows it. A response is final. The other actions ask a bridge or the workflow. The action must fit the item now; `getInboxItem` lists the actions that fit. The links `open` and `review` are not actions of the API.', nullable: false, enum: self::ACTIONS)]
    public ?string $action = null;

    #[Assert\Length(max: RunInboxActionRequest::MAX_RESPONSE_LENGTH)]
    #[OA\Property(description: 'The words of the owner for a response, and a short text that any other action keeps in Loupe.', maxLength: RunInboxActionRequest::MAX_RESPONSE_LENGTH)]
    public string $note;

    /** @var list<int> */
    #[Assert\All(new Assert\Type('int'))]
    #[OA\Property(description: 'The indexes of the options for an `answer`, counting from 0.', type: 'array', items: new OA\Items(type: 'integer', minimum: 0))]
    public array $selectedOptions;

    #[OA\Property(description: '`run-command` only. The branch to run on. The branch of the suggestion when you leave it out. Send the branch of the preview.')]
    public string $branch;

    /** @var array<string, string> */
    #[Assert\All(new Assert\Type('string'))]
    #[OA\Property(description: '`run-command` only. The value of each param, by name. The values of the suggestion when you leave it out. Send the params of the preview.', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'))]
    public array $params;

    #[OA\Property(description: '`run-command` only, and required for it. The `runDigest` of the preview that the owner confirmed. The command runs only when it matches the command the bridge reports now.')]
    public string $runDigest;

    #[OA\Property(description: '`start-session` only. The prompt to send. The prompt of the suggestion when you leave it out, and none when it is an empty string.')]
    public string $sessionPrompt;

    #[Assert\Callback]
    public function paramsAreAnObject(ExecutionContextInterface $context): void
    {
        if (isset($this->params) && [] !== $this->params && array_is_list($this->params)) {
            $context->buildViolation('inbox.api.error.params_not_object')->atPath('params')->addViolation();
        }
    }
}
