<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;

/** A null run carries the reason the report was refused. */
final readonly class ReportInteractiveLaunchResult
{
    public const string PROJECT_NOT_FOUND = 'project_not_found';
    public const string UNKNOWN_SUBJECT_TYPE = 'unknown_subject_type';
    public const string SUBJECT_NOT_FOUND = 'subject_not_found';

    public function __construct(
        public ?WorkerRun $run,
        public bool $created,
        public string $error = self::PROJECT_NOT_FOUND,
    ) {
    }
}
