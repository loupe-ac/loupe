<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Api\Problem\ApiProblem;
use App\Api\Problem\ApiProblemException;
use App\Exception\DomainErrors;
use App\Module\Board\Command\MarkFeedbackAddressedHandler;
use App\Module\Board\Command\PageFeedbackHandler;

/** Turns what a feedback handler refuses with into the coded problems of the public API. */
final readonly class FeedbackApiProblems
{
    public function feedbackNotFound(): ApiProblemException
    {
        return new ApiProblemException(new ApiProblem(404, 'feedback_not_found', 'Feedback not found', 'This project has no such feedback item.'));
    }

    /** A refusal that no code covers stays a DomainErrors, so it is logged as a failure. */
    public function from(DomainErrors $errors): ApiProblemException|DomainErrors
    {
        return match ($errors->errors['feedback'] ?? $errors->errors['cardId'] ?? null) {
            MarkFeedbackAddressedHandler::NOT_FOUND => $this->feedbackNotFound(),
            MarkFeedbackAddressedHandler::RESOLVED => new ApiProblemException(new ApiProblem(409, 'feedback_already_resolved', 'Feedback already resolved', 'A person resolved this item, so it cannot be addressed.'), $errors),
            PageFeedbackHandler::CARD_NOT_FOUND => new ApiProblemException(new ApiProblem(404, 'card_not_found', 'Card not found', 'This project has no card with this id.'), $errors),
            default => $errors,
        };
    }
}
