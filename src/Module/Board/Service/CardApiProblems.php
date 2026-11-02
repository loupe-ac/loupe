<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\ChildDesignRefused;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Bridge\Command\PauseCardAgentsHandler;
use App\Module\Bridge\Command\ReleaseCardAgentsHandler;

/** Turns what a card handler refuses with into the coded problems of the public API. */
final readonly class CardApiProblems
{
    public function cardNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'card_not_found', 'Card not found', 'This project has no card with this number.'));
    }

    /** @param array{field: string, code: string, message: string} $error */
    public function invalid(array $error): ApiProblemException
    {
        return ApiProblemException::validationFailed([$error]);
    }

    /** What a hold or the end of a hold refuses with. A refusal that no code covers stays as it is. */
    public function fromHold(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        return match (array_first($errors->errors)) {
            PauseCardAgentsHandler::ALREADY_PAUSED => new ApiProblemException(new ApiProblem(409, 'already_paused', 'Already held', 'This card is already unmanaged.'), $errors),
            PauseCardAgentsHandler::CARD_GONE => new ApiProblemException($this->cardNotFound()->problem, $errors),
            ReleaseCardAgentsHandler::NOT_PAUSED => new ApiProblemException(new ApiProblem(409, 'not_paused', 'Not paused', 'This card has no hold or no pause to end.'), $errors),
            default => $errors,
        };
    }

    /** What the end of a workflow pause refuses with. The keys are those of the workflow handler, which Board may not import. */
    public function fromPauseRelease(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        return match (array_first($errors->errors)) {
            'workflow.pause_release.error.card_unmanaged' => new ApiProblemException(new ApiProblem(409, 'card_unmanaged', 'Card unmanaged', 'The card is unmanaged, or the workflow is off for the project.'), $errors),
            'workflow.pause_release.error.not_paused' => new ApiProblemException(new ApiProblem(409, 'not_paused', 'Not paused', 'This card has no hold or no pause to end.'), $errors),
            'workflow.pause_release.error.pause_changed' => new ApiProblemException(new ApiProblem(409, 'pause_changed', 'Pause changed', 'The active pause is not the pause named by pauseId.'), $errors),
            'workflow.pause_release.error.kind_not_releasable' => new ApiProblemException(new ApiProblem(409, 'kind_not_releasable', 'Pause kind not releasable', 'A pause of kind rule ends only on its own release condition.'), $errors),
            default => $errors,
        };
    }

    /**
     * A conflict decides the answer, and the other refusals are fields the client can correct.
     * A refusal that no code covers stays as it is, so it is logged as a failure.
     */
    public function from(DomainErrors|CardManaged|EpicChildrenOpen|ChildDesignRefused $refusal): ApiProblemException|DomainErrors
    {
        if ($refusal instanceof CardManaged) {
            return new ApiProblemException(new ApiProblem(409, 'card_managed', 'Card managed', 'This card is managed, and its workflow does not allow this move. PUT the hold first, and then any move is allowed.'), $refusal);
        }
        if ($refusal instanceof EpicChildrenOpen) {
            return new ApiProblemException(new ApiProblem(409, 'epic_children_open', 'Open child cards', \sprintf('This epic has open child cards %s. Move each of them to a terminal column first.', $refusal->cardList()), ['cards' => $refusal->numbers]), $refusal);
        }
        if ($refusal instanceof ChildDesignRefused && null !== $refusal->fieldCode) {
            $problem = ApiProblemException::validationFailed([['field' => 'childDesign', 'code' => $refusal->fieldCode, 'message' => preg_replace('/^childDesign: /', '', $refusal->getMessage()) ?? $refusal->getMessage()]]);

            return new ApiProblemException($problem->problem, $refusal);
        }
        if ($refusal instanceof ChildDesignRefused) {
            return new ApiProblemException(new ApiProblem(409, 'child_design_refused', 'Child design refused', $refusal->getMessage()), $refusal);
        }

        $fields = [];
        foreach ($refusal->errors as $field => $key) {
            $invalid = $this->invalidField($field, $key, $refusal->parameters[$field] ?? []);
            if (null === $invalid) {
                return $refusal;
            }
            $fields[] = $invalid;
        }

        $problem = ApiProblemException::validationFailed($fields);

        return new ApiProblemException($problem->problem, $refusal);
    }

    /**
     * @param array<string, int|string> $parameters the placeholders of the key
     *
     * @return array{field: string, code: string, message: string}|null
     */
    private function invalidField(string $field, string $key, array $parameters): ?array
    {
        if (str_starts_with($field, 'values.')) {
            return match (true) {
                'board.card.error.value_key_undeclared' === $key => ['field' => $field, 'code' => 'value_undeclared_key', 'message' => \sprintf('The workflow template declares no key %s. Declared keys: %s.', $parameters['%key%'] ?? '', $parameters['%declared%'] ?? '')],
                'board.card.error.value_key_undeclared_none' === $key => ['field' => $field, 'code' => 'value_undeclared_key', 'message' => \sprintf('The workflow template declares no key %s. Declared keys: none.', $parameters['%key%'] ?? '')],
                str_starts_with($key, 'board.card.error.value_type_') => ['field' => $field, 'code' => 'value_wrong_type', 'message' => 'This key takes true or false.'],
                default => null,
            };
        }

        return match ($key) {
            'board.card.error.title_blank' => ['field' => 'title', 'code' => 'title_blank', 'message' => 'A card title must not be blank.'],
            'board.card.error.title_too_long' => ['field' => 'title', 'code' => 'title_too_long', 'message' => \sprintf('A card title must be at most %d characters.', Card::MAX_TITLE_LENGTH)],
            'board.card.error.pull_request_url_too_long' => ['field' => 'pullRequestUrls', 'code' => 'pull_request_url_too_long', 'message' => \sprintf('A pull request URL must be at most %d characters.', CardPullRequest::MAX_URL_LENGTH)],
            'board.card.error.document_unknown' => ['field' => 'documentIds', 'code' => 'document_unknown', 'message' => 'One of those document ids names no document of this project.'],
            'board.card.error.artifact_unknown' => ['field' => 'artifactIds', 'code' => 'artifact_unknown', 'message' => 'One of those artifact ids names no artifact of this project.'],
            'board.card.error.linked_card_unknown' => ['field' => 'relatedCards', 'code' => 'linked_card_unknown', 'message' => 'One of those card ids names no card of this project.'],
            'board.card.error.linked_card_self' => ['field' => 'relatedCards', 'code' => 'linked_card_self', 'message' => 'A card cannot link to itself. Remove its own id from relatedCards.'],
            'board.card.error.linked_card_twice' => ['field' => 'relatedCards', 'code' => 'linked_card_twice', 'message' => 'The same card appears twice in relatedCards. Name each card once, with one kind.'],
            'board.card.error.parent_unknown' => ['field' => 'parentCardId', 'code' => 'parent_unknown', 'message' => 'That parentCardId names no card of this project.'],
            'board.card.error.parent_not_epic' => ['field' => 'parentCardId', 'code' => 'parent_not_epic', 'message' => 'Only a card of a type with the children capability can be a parent.'],
            'board.card.error.epic_cannot_have_parent' => ['field' => 'parentCardId', 'code' => 'epic_cannot_have_parent', 'message' => 'A card of a type with the children capability cannot have a parent, because those cards do not nest.'],
            'board.card.error.parent_card_cannot_be_epic' => ['field' => 'type', 'code' => 'parent_card_cannot_be_epic', 'message' => 'A card with a parent cannot take a type with the children capability. Clear its parent with clearParent first.'],
            'board.card.error.epic_type_locked' => ['field' => 'type', 'code' => 'epic_type_locked', 'message' => 'This card has child cards, so its type stays. Clear the parent of each child first.'],
            'board.card.error.column_gone' => ['field' => 'status', 'code' => 'unknown_status', 'message' => 'That column no longer exists on this board. Name another column.'],
            'board.card.error.parent_clear_conflict' => ['field' => 'parentCardId', 'code' => 'parent_clear_conflict', 'message' => 'Pass clearParent or parentCardId, not both.'],
            default => null,
        };
    }
}
