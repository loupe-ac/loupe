<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxAction;
use Symfony\Component\Validator\Constraints as Assert;

class RunInboxActionRequest
{
    public function __construct(
        #[Assert\Length(max: InboxAction::MAX_NOTE_LENGTH)]
        public ?string $note = null,
    ) {
    }
}
