<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The kind of thing a stored marker entry names. */
enum MarkerSubjectType: string
{
    case Document = 'document';
    case PullRequest = 'pull-request';
    case WorkerRun = 'worker-run';
    case CardPause = 'card-pause';
    case Card = 'card';
}
