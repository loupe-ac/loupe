<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\FormerWorkSubjectInterface;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Finds the work request that the report of a new run names. A run takes the subject of its request. */
final readonly class ReportedWorkRequest
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WorkSubjectHandlers $subjects,
    ) {
    }

    /** The request of the project about the reported subject, or about the subject that replaced it. Else null. */
    public function find(Uuid $id, Project $project, WorkSubject $subject): ?WorkRequest
    {
        $request = $this->workRequests->findOneOfSubject($id, $project, $subject);
        if (null !== $request) {
            return $request;
        }

        $moved = $this->workRequests->findOneBy(['id' => $id, 'project' => $project]);
        if (null === $moved || !$this->subjects->has($moved->subjectType)) {
            return null;
        }
        $handler = $this->subjects->for($moved->subjectType);

        return $handler instanceof FormerWorkSubjectInterface && $handler->isFormerSubjectOf($moved, $subject) ? $moved : null;
    }
}
