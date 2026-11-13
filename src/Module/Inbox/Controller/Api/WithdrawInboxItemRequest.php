<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\PartialChangeSchemaProcessor;
use App\Module\Inbox\InboxLimits;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** The reason to take an item back. The handler refuses a blank one and one that is too long. */
#[OA\Schema(x: [PartialChangeSchemaProcessor::MARKER => ['reason']])]
final class WithdrawInboxItemRequest
{
    #[Assert\NotNull]
    #[OA\Property(description: 'Why the item is no longer needed. The owner reads it in the note of the item.', nullable: false, maxLength: InboxLimits::MAX_WITHDRAW_REASON_LENGTH)]
    public ?string $reason = null;
}
