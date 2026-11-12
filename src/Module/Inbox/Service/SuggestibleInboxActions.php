<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

/** The built-in actions a worker can suggest on an item, each with one line on what it does. */
final readonly class SuggestibleInboxActions
{
    /** Add entry is the owner's own step and has no line here. */
    public const array EFFECTS = [
        'resume-run' => 'Wakes the stopped worker so it carries on with its session.',
        'retry-card' => 'Releases the pause of the card so the workflow runs the rule again.',
        'review' => 'Opens the page where the owner reviews the work.',
        'open' => 'Opens the card or the document.',
        'send-agent' => 'Starts a new worker for the stage work of the column the card sits in.',
        'start-session' => 'Opens an interactive session on the card, on a bridge that can open one.',
        'run-command' => 'Asks the bridge of the asking worker to run one named command entry of its rules.yaml after the owner confirms.',
        'dismiss' => 'Closes the item without an action.',
    ];
}
