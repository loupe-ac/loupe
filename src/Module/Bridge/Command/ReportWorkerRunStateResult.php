<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/**
 * What a state report did. A null run means the owner has no project by that
 * handle, or the error says why. A non-null $error says why the report was refused. A false $newState means the run already held the state, so nothing
 * changed.
 */
final readonly class ReportWorkerRunStateResult
{
    public const string UNKNOWN_SUBJECT_TYPE = 'unknown_subject_type';
    public const string SUBJECT_NOT_FOUND = 'subject_not_found';
    public const string WORK_REQUEST_NOT_FOUND = 'work_request_not_found';

    public function __construct(
        public ?WorkerRun $run,
        public bool $newState,
        public ?string $error = null,
    ) {
    }
}
