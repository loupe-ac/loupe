<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Turns the opaque scope the widget sends with its list request into the ids
 * of the comments in it.
 *
 * SiteReview must not know what a scope means. The module that wrote the
 * marker implements this, so the dependency runs towards SiteReview.
 *
 * Returning null is the ordinary answer for a scope the resolver does not
 * recognise. A resolver must also drop anything that belongs to another
 * project, because the scope arrives from a page anyone can edit.
 */
#[AutoconfigureTag('app.site_review_comment_scope_resolver')]
interface CommentScopeResolverInterface
{
    /** @return list<Uuid>|null the comment ids in scope, or null for a scope it does not know */
    public function resolve(string $scope, Project $project): ?array;
}
