<?php

declare(strict_types=1);

namespace App\Module\Project\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlerInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Owns the subject type of a run about a project itself, such as the run that `loupe init` holds open.
 * Such a run has no work request, so nothing happens when a request settles or expires.
 */
final readonly class ProjectWorkSubjectHandler implements WorkSubjectHandlerInterface
{
    #[\Override]
    public static function subjectType(): string
    {
        return WorkSubject::PROJECT;
    }

    #[\Override]
    public function subjectExists(Project $project, Uuid $subjectId): bool
    {
        return null !== $project->id && $project->id->equals($subjectId);
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
