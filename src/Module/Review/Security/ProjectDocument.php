<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Marks a `Document` controller argument that a public API route resolves from a path
 * segment, inside the project of the `{project}` segment. A document of another project
 * answers 404 `document_not_found`, the same as a document that does not exist.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ProjectDocument extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(ProjectDocumentValueResolver::NAME);
    }
}
