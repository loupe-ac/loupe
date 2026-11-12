<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Mcp\InboxToolErrorMessages;

/** Turns what an inbox handler refuses with into the coded problems of the public API. */
final readonly class InboxApiProblems
{
    public function __construct(
        private InboxToolErrorMessages $messages,
    ) {
    }

    /** The sentence that a script reads for a refusal key. */
    public function sentence(string $key): string
    {
        return $this->messages->sentence($key);
    }

    public function itemNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'inbox_item_not_found', 'Inbox item not found', 'This project has no inbox item with this id.'));
    }

    /**
     * What a run of an action refuses with. A closed item and a changed command are conflicts the caller
     * can act on, so each has a code of its own. Every other refusal is a field the caller can correct.
     *
     * @param bool $needsOpenItem false for the one action that a closed item still takes
     */
    public function fromAction(DomainErrors $refusal, InboxItem $item, bool $needsOpenItem): ApiProblemException|DomainErrors
    {
        $keys = array_values($refusal->errors);
        if (\in_array(RunInboxActionHandler::COMMAND_CHANGED, $keys, true)) {
            return new ApiProblemException(new ApiProblem(409, 'run_digest_stale', 'Run digest stale', 'The command changed since the preview, or the digest is not the one of the last preview. Read the preview again, show it to the owner, and send the new runDigest.'), $refusal);
        }
        // The action handler answers one refusal for every state that blocks it, so the state of the item tells a closed item apart.
        $closed = InboxItemState::Open !== $item->state;
        if (\in_array(InboxItemCloser::ITEM_NOT_OPEN, $keys, true) || \in_array(InboxItemCloser::ERROR_FINAL, $keys, true) || ($needsOpenItem && $closed && \in_array(RunInboxActionHandler::NOT_AVAILABLE, $keys, true))) {
            return $this->itemNotOpen($refusal);
        }

        return $this->from($refusal);
    }

    /** What a withdrawal refuses with: a closed item and an item no caller withdraws are conflicts. */
    public function fromWithdrawal(DomainErrors $refusal): ApiProblemException|DomainErrors
    {
        $keys = array_values($refusal->errors);
        if (\in_array(InboxItemCloser::ITEM_NOT_OPEN, $keys, true)) {
            return $this->itemNotOpen($refusal);
        }
        if ([] !== array_intersect($keys, [WithdrawInboxItemHandler::WAIT_NOT_WITHDRAWABLE, WithdrawInboxItemHandler::NOTICE_NOT_WITHDRAWABLE, WithdrawInboxItemHandler::WORKFLOW_NOT_WITHDRAWABLE])) {
            return new ApiProblemException(new ApiProblem(409, 'item_not_withdrawable', 'Item not withdrawable', 'Loupe or a workflow rule opened this item, so no caller withdraws it. It closes when what it waits for ends.'), $refusal);
        }

        return $this->from($refusal);
    }

    private function itemNotOpen(DomainErrors $refusal): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(409, 'item_not_open', 'Item not open', 'The item is closed, so this request cannot change it. Read its state with getInboxItem.'), $refusal);
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
