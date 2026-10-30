<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Bridge\Entity\WorkRequest;
use Symfony\Component\Validator\Constraints as Assert;

class RunInboxActionRequest
{
    public const int MAX_PARAMS = 50;

    /** The longest text of a response: an answer, a closing note or a review note. */
    public const int MAX_RESPONSE_LENGTH = 10000;

    /**
     * @param array<string, string> $params the parameter values the owner confirmed for a run-command action
     */
    public function __construct(
        /** The words of the owner: the note of an action, or the answer, the closing note or the review note of a response. */
        #[Assert\Length(max: self::MAX_RESPONSE_LENGTH)]
        public ?string $note = null,

        /** The branch the owner confirmed for a run-command action. */
        #[Assert\Length(max: WorkRequest::MAX_BRANCH_LENGTH)]
        public ?string $branch = null,

        #[Assert\Count(max: self::MAX_PARAMS)]
        public array $params = [],

        /** The digest of the run list the confirm dialog showed. */
        #[Assert\Length(max: 64)]
        public ?string $runDigest = null,
        /** The prompt of a start-session action, as the owner left it in the field. The handler checks its length against the flag. */
        public ?string $sessionPrompt = null,

        /** Comma-separated option indexes of an answer, which the option controls fill. */
        #[Assert\Length(max: 2000)]
        public ?string $selectedOptions = null,

        /** The verdict of a review. The handler checks the value. */
        #[Assert\Length(max: 20)]
        public ?string $verdict = null,

        /** The document version the owner read. The review is refused when a newer one arrived. */
        #[Assert\Positive]
        public ?int $versionNumber = null,

        /** The newest review of the version when the owner opened the page, which a new review of the document must still follow. */
        #[Assert\Uuid]
        public ?string $expectedReviewId = null,

        /** The pull request the owner read. The review is refused when the item names another one. */
        #[Assert\Length(max: 2048)]
        public ?string $expectedUrl = null,
    ) {
    }
}
