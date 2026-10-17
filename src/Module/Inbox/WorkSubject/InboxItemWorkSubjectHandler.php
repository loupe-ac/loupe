<?php

declare(strict_types=1);

namespace App\Module\Inbox\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlerInterface;
use App\Module\Inbox\Entity\InboxItem;

/**
 * Lets an inbox item with no card carry a work request. The item stays open when the work
 * ends or expires, and the status of the action shows the outcome, so there is nothing to settle.
 */
final readonly class InboxItemWorkSubjectHandler implements WorkSubjectHandlerInterface
{
    #[\Override]
    public static function subjectType(): string
    {
        return InboxItem::SUBJECT_TYPE;
    }

    #[\Override]
    public function onSettled(WorkRequest $request): void
    {
    }

    #[\Override]
    public function onExpired(WorkRequest $request): void
    {
    }
}
