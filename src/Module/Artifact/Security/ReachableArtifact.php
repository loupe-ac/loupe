<?php

declare(strict_types=1);

namespace App\Module\Artifact\Security;

use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Marks an `Artifact` controller argument that a public API route resolves from the
 * `project` and `artifact` path segments. A project the credential does not reach
 * answers 404 `project_not_found`, and an artifact of another project answers 404
 * `artifact_not_found`, the same as one that does not exist.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ReachableArtifact extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(ReachableArtifactValueResolver::NAME);
    }
}
