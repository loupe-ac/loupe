<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Marks a `Project` controller argument that a public API route resolves from a
 * path segment holding an id or a slug. A project the credential does not reach
 * answers 404 `project_not_found`, the same as a project that does not exist.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ReachableProject extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(ReachableProjectValueResolver::NAME);
    }
}
