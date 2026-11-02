<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Command\MarkSiteReviewCommentAddressedOutcome;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentsAddressedHandler;

/**
 * Marks one item addressed. An item that is already addressed stays as it is. A person's
 * resolution is never replaced, so a resolved item refuses.
 */
final readonly class MarkFeedbackAddressedHandler
{
    public const string NOT_FOUND = 'board.feedback.error.not_found';
    public const string RESOLVED = 'board.feedback.error.resolved';

    public function __construct(
        private ShowFeedbackHandler $showFeedback,
        private MarkSiteReviewCommentsAddressedHandler $markAddressed,
    ) {
    }

    /** @throws DomainErrors when the item is unknown or resolved */
    public function __invoke(MarkFeedbackAddressedCommand $command): ShowFeedbackView
    {
        $view = ($this->showFeedback)(new ShowFeedbackCommand($command->project, $command->feedbackId))
            ?? throw new DomainErrors(['feedback' => self::NOT_FOUND]);

        $outcome = ($this->markAddressed)(new MarkSiteReviewCommentsAddressedCommand([$view->feedback]))[0];

        return match ($outcome) {
            MarkSiteReviewCommentAddressedOutcome::Addressed, MarkSiteReviewCommentAddressedOutcome::AlreadyAddressed => $view,
            MarkSiteReviewCommentAddressedOutcome::AlreadyResolved => throw new DomainErrors(['feedback' => self::RESOLVED]),
            MarkSiteReviewCommentAddressedOutcome::NotFound => throw new DomainErrors(['feedback' => self::NOT_FOUND]),
        };
    }
}
