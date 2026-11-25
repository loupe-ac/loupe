<?php

declare(strict_types=1);

namespace App\Module\Bridge\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkSubject;

/**
 * A subject handler whose requests moved to a new subject while bridges held them. A bridge that claimed a request
 * before the move reports its worker with the old subject. Temporary: it goes when no request has a former subject.
 */
interface FormerWorkSubjectInterface
{
    /** Whether the reported subject is the one the request had before. The request belongs to the project of the report. */
    public function isFormerSubjectOf(WorkRequest $request, WorkSubject $subject): bool;
}
