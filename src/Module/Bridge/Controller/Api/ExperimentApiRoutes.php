<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

/** The route requirements that the public experiment routes share. */
final readonly class ExperimentApiRoutes
{
    /** Nine digits at most, so the number always fits the integer column. */
    public const string CARD_NUMBER = '[1-9][0-9]{0,8}';
}
