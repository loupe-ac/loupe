<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** What the merge question looks like for one repository now. */
final readonly class MergeApproval
{
    /**
     * @param bool $declared       the template of the project declares the setting
     * @param bool $person         the setting resolves to person
     * @param bool $unset          the setting resolves to no value: no valid file value and no stored value
     * @param bool $invalidInFile  `.loupe.yaml` holds a value that is not allowed
     * @param bool $requiresReview the rules of the default branch require an approving review
     */
    public function __construct(
        public bool $declared,
        public bool $person,
        public bool $unset,
        public bool $invalidInFile,
        public bool $requiresReview,
    ) {
    }

    /** The unanswered case, once the caller knows whether Loupe can post its check on the repository. */
    public function unanswered(bool $canPostCheck): bool
    {
        return $this->declared && $this->unset && $canPostCheck && !$this->requiresReview;
    }

    /** A pull request waits for a person: the owner chose person, or the question has no answer. */
    public function needsPerson(bool $canPostCheck): bool
    {
        return $this->declared && ($this->person || $this->unanswered($canPostCheck));
    }
}
