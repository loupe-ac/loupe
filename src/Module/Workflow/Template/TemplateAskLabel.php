<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\AskMode;

/** One label of the `asks:` section and the mode that Loupe gives its asks. */
final readonly class TemplateAskLabel
{
    public function __construct(
        public string $key,
        public AskMode $mode,
    ) {
    }
}
