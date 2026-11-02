<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Marks a `Comment` controller argument that a public API route resolves from the `{comment}`
 * segment, inside the document of the `{document}` segment. A comment of another document
 * answers 404 `comment_not_found`, the same as a comment that does not exist.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class DocumentComment extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(DocumentCommentValueResolver::NAME);
    }
}
