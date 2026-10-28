<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Why an interactive run closed. The run page shows it as a short label. */
enum WorkerRunCloseReason: string
{
    /** The session closed its own run, or the exit hook of the plugin did. */
    case SessionEnded = 'session-ended';

    /** A person closed the run from the card or the Runs page. */
    case Person = 'person';

    /** The card moved to another column. */
    case CardMoved = 'card-moved';

    /** The run showed no sign of life for longer than the stale threshold. */
    case Stale = 'stale';

    /** The bridge that launched the session found its process gone. */
    case SessionGone = 'session-gone';
}
