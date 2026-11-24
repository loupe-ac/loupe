<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

/** A wait of a worker ended, so its bridge resumes the session of the run that waited. */
final readonly class ResumeWaitingSession
{
    public function __construct(
        public string $waitId,
    ) {
    }
}
