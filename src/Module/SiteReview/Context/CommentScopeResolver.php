<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/** Asks each resolver in turn and takes the first answer. */
final readonly class CommentScopeResolver
{
    /** @param iterable<CommentScopeResolverInterface> $resolvers */
    public function __construct(
        #[AutowireIterator('app.site_review_comment_scope_resolver')]
        private iterable $resolvers,
    ) {
    }

    /** @return list<Uuid>|null the comment ids in scope, or null when no resolver knows the scope */
    public function resolve(string $scope, Project $project): ?array
    {
        $scope = trim($scope);
        if ('' === $scope) {
            return null;
        }

        foreach ($this->resolvers as $resolver) {
            $ids = $resolver->resolve($scope, $project);
            if (null !== $ids) {
                return $ids;
            }
        }

        return null;
    }
}
