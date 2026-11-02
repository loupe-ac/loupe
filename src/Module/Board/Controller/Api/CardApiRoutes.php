<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

/** The route requirements that the public card routes share. */
final readonly class CardApiRoutes
{
    /** Nine digits at most, so the number always fits the integer column. */
    public const string NUMBER = '[1-9][0-9]{0,8}';
}
