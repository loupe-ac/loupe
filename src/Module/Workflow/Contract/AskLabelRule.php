<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** How the template of a project treats one ask label, after its switches. */
final readonly class AskLabelRule
{
    /** What a label and a detail field name look like: lowercase words joined by hyphens. */
    public const string NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    public const int MAX_NAME_LENGTH = 40;

    /**
     * @param list<string> $requiredFields the detail fields every labelled ask carries
     */
    public function __construct(
        public AskMode $mode,
        public int $defaultHours,
        public array $requiredFields = [],
    ) {
    }
}
