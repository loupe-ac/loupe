<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Security\ApiKeyActor;

final readonly class SubmitReviewCommand
{
    public function __construct(
        public User $reviewer,
        public Document $document,
        // Raw submitted value — parsed into a Verdict by the handler, which
        // throws DomainErrors on an unrecognised value.
        public string $verdict,
        public int $versionNumber,
        public ?string $note = null,
        public ?string $expectedReviewId = null,
        // The id of the workflow rule that writes the verdict. The reviewer is then the workflow user.
        public ?string $workflowRule = null,
        public bool $throughAgent = false,
        // The API key that writes the verdict for its owner. Never set together with throughAgent.
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
