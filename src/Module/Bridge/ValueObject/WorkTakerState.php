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

    /** No online bridge could take the work. */
    case None = 'none';
}
