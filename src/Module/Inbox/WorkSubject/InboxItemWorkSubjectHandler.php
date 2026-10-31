<?php

declare(strict_types=1);

namespace App\Module\Inbox\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlerInterface;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Lets an inbox item with no card carry a work request. The item stays open when the work
 * ends or expires, and the status of the action shows the outcome, so there is nothing to settle.
 */
final readonly class InboxItemWorkSubjectHandler implements WorkSubjectHandlerInterface
{
    public function __construct(
        private InboxItemRepository $inboxItems,
    ) {
    }

    #[\Override]
    public static function subjectType(): string
    {
        return InboxItem::SUBJECT_TYPE;
    }

    #[\Override]
    public function subjectExists(Project $project, Uuid $subjectId): bool
    {
        return null !== $this->inboxItems->findOneByIdAndProjectId($subjectId->toRfc4122(), ($project->id ?? throw new \LogicException('A stored project has an id.'))->toRfc4122());
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
