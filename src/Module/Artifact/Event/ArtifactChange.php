<?php

declare(strict_types=1);

namespace App\Module\Artifact\Event;

/** What happened to an artifact, as the open pages of its project read it. */
enum ArtifactChange: string
{
    case Stored = 'stored';
    case Archived = 'archived';
    case Unarchived = 'unarchived';
}
