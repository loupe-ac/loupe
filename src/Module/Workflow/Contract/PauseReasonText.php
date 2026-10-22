<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The text of a pause reason or of a rule refusal. A code with no text shows as it is, because a forge write may refuse with a cause from the forge. */
interface PauseReasonText
{
    /** A retries pause keeps the code of its last refusal, so a pause code falls back to the refusal texts. */
    public function forPause(string $code): string;

    public function forRefusal(string $code): string;
}
