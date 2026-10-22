<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Workflow\Contract\MarkerSubjectType;

/** What a card waits on. The page turns the type and the label into a sentence. */
enum InboxCardWaitType: string
{
    case Document = 'document';
    case PullRequest = 'pull-request';
    case WorkerRun = 'worker-run';
    case CardPause = 'card-pause';

    /** The card itself: one spell of a marker that names no single thing. */
    case Card = 'card';

    public static function forSubject(MarkerSubjectType $type): self
    {
        return match ($type) {
            MarkerSubjectType::Document => self::Document,
            MarkerSubjectType::PullRequest => self::PullRequest,
            MarkerSubjectType::WorkerRun => self::WorkerRun,
            MarkerSubjectType::CardPause => self::CardPause,
            MarkerSubjectType::Card => self::Card,
        };
    }

    /**
     * The names of the ids that tell one wait of this type from another, as a marker subject names them.
     *
     * @return list<string>
     */
    public function idNames(): array
    {
        return match ($this) {
            self::Document => ['document', 'version'],
            self::PullRequest => ['pullRequest', 'head'],
            self::WorkerRun => ['run'],
            self::CardPause => ['pause'],
            self::Card => ['card', 'spell'],
        };
    }
}
