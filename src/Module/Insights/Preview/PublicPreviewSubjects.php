<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Insights\Entity\PublicLink;

/** Reads the result that the share page shows for a link, with the version of that result. */
interface PublicPreviewSubjects
{
    /** @return PublicPreviewSubject|null null when the link has no image to draw */
    public function read(PublicLink $link): ?PublicPreviewSubject;
}
