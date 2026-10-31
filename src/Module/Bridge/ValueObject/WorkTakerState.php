<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Whether a bridge could take a waiting work request right now. */
enum WorkTakerState: string
{
    /** An online bridge that is not paused can take the work. */
    case Available = 'available';

    /** Every online bridge that could take the work is paused. */
    case Paused = 'paused';

    /** Every online bridge that could take the work has the account for it blocked by a usage limit or a stop value. */
    case Limited = 'limited';

    /** No online bridge follows the project. */
    case None = 'none';

    /** An online bridge follows the project, and none of them could take the work. */
    case NotCapable = 'not-capable';

    /** The bridge of the claim sent no heartbeat, or the work waits a few minutes after a network outage. */
    case Disconnected = 'disconnected';
}
