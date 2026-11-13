<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Marks a `Series` controller argument that a public API route resolves from a path
 * segment, inside the project of the `{project}` segment. The name matches ignoring case.
 * A series of another project answers 404 `series_not_found`, the same as a missing one.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ProjectSeries extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(ProjectSeriesValueResolver::NAME);
    }
}
