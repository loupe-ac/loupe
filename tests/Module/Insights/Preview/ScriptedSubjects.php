<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Preview\PublicPreviewSubject;
use App\Module\Insights\Preview\PublicPreviewSubjects;

/** Returns the scripted subjects in order, and the last one for every read after that. */
final class ScriptedSubjects implements PublicPreviewSubjects
{
    /** @param list<PublicPreviewSubject> $script */
    public function __construct(
        public array $script,
    ) {
    }

    #[\Override]
    public function read(PublicLink $link): ?PublicPreviewSubject
    {
        return \count($this->script) > 1 ? array_shift($this->script) : ($this->script[0] ?? null);
    }
}
