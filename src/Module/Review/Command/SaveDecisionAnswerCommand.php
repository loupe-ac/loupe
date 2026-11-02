<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Security\ApiKeyActor;

final readonly class SaveDecisionAnswerCommand
{
    /**
     * @param list<int> $optionIndexes indexes into the options of the displayed version
     */
    public function __construct(
        public Document $document,
        public string $decisionId,
        public int $displayedVersionNumber,
        public array $optionIndexes,
        public ?string $note,
        public bool $clear,
        public User $answeredBy,
        public bool $throughAgent = false,
        // Refuse the save when the displayed version is not the latest one.
        public bool $requireLatestVersion = false,
        // Keep the stored note and ignore the one in the command.
        public bool $keepNote = false,
        // The API key that saves the answer for its owner. Never set together with throughAgent.
        public ?ApiKeyActor $apiKey = null,
    ) {
    }
}
