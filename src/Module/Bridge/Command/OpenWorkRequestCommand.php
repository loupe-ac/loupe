<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class OpenWorkRequestCommand
{
    public function __construct(
        public Project $project,
        public WorkSubject $subject,
        /** The card number a person sees. A card subject needs one, and any other subject has none. */
        public ?int $cardNumber,
        public string $kind,
        public ?string $capability,
        public string $ruleId,
        public WorkRequestContext $context,
        public ?string $model = null,
        public ?string $effort = null,
        public ?string $prompt = null,
        /** Starts a new session even when an unfinished run of the card could resume. */
        public bool $fresh = false,
        public ?Uuid $inboxActionId = null,
        public ?int $inboxItemNumber = null,
    ) {
    }
}
