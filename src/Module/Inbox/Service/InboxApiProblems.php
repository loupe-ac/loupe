<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Inbox\Mcp\InboxToolErrorMessages;

/** Turns what an inbox handler refuses with into the coded problems of the public API. */
final readonly class InboxApiProblems
{
    public function __construct(
        private InboxToolErrorMessages $messages,
    ) {
    }

    public function itemNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'inbox_item_not_found', 'Inbox item not found', 'This project has no inbox item with this id.'));
    }

    /**
     * Each refused field with the last part of its key as the code, and the sentence an agent reads.
     * A key with no sentence stays as it is, so it is logged as a failure.
     */
    public function from(DomainErrors $refusal): ApiProblemException|DomainErrors
    {
        $fields = [];
        foreach ($refusal->errors as $field => $key) {
            $message = $this->messages->sentence($key);
            if (InboxToolErrorMessages::UNMAPPED === $message) {
                return $refusal;
            }
            $fields[] = ['field' => $field, 'code' => substr($key, (int) strrpos($key, '.') + 1), 'message' => $message];
        }

        return new ApiProblemException(ApiProblemException::validationFailed($fields)->problem, $refusal);
    }
}
