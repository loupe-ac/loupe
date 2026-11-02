<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\Command\ShowPendingCommentsCommand;
use App\Module\SiteReview\Command\ShowPendingCommentsHandler;
use App\Module\SiteReview\Context\CommentLinkResolverInterface;
use App\Module\SiteReview\Context\CommentScopeResolver;
use App\Module\SiteReview\Context\ContextLabelResolver;
use App\Module\SiteReview\Entity\ReviewSurface;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use App\Module\SiteReview\ParentType\ParentType;
use App\Module\SiteReview\ParentType\ParentTypeProviderInterface;
use App\Module\SiteReview\SiteReviewDrawing;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * `/review` rather than `/comments`: the path is a public contract embedded in
 * every deployed widget, so it stays put even though it no longer serves a
 * review batch.
 */
#[Route(
    '/api/live-review/review',
    name: 'api_site_review_pending_comments',
    methods: ['GET'],
)]
final class ShowPendingCommentsController extends AppController
{
    public function __construct(
        private readonly ShowPendingCommentsHandler $showPendingComments,
        private readonly AuthenticatedProjectResolver $projectResolver,
        private readonly FeatureFlagService $featureFlags,
        private readonly ContextLabelResolver $contextLabels,
        private readonly CommentScopeResolver $commentScopes,
        private readonly ParentTypeProviderInterface $parentTypes,
        private readonly CommentLinkResolverInterface $commentLinks,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $project = $this->projectResolver->resolveWidgetProject();
        if (null === $project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_FORBIDDEN, 'token_not_bound_to_site', 'The token is not bound to a site.');
        }

        // Only an absent scope keeps the project-wide list, because a browser
        // can hold an old copy of the script for a long time. A scope nothing
        // resolves, an empty one included, lists nothing.
        $commentIds = $request->query->has('scope')
            ? $this->commentScopes->resolve((string) $request->query->get('scope'), $project) ?? []
            : null;

        // An absent filter keeps the web notes, which is all a widget copy asks for.
        $surface = $request->query->has('surface')
            ? ReviewSurface::tryFrom((string) $request->query->get('surface'))
            : ReviewSurface::Web;
        if (null === $surface) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'surface_invalid', 'The surface is not valid.');
        }

        $view = ($this->showPendingComments)(new ShowPendingCommentsCommand($project, $commentIds, $surface));

        // The marker is the page's preview card or the target the widget
        // stored for its mode. A marker nothing resolves comes back as null,
        // which tells the widget to ask for the mode again.
        $context = $request->query->get('context');
        $label = $this->contextLabels->resolve(\is_string($context) ? $context : null, $project);
        $links = $this->commentLinks->resolve(array_values($view->comments), $project);

        // The widget carries no flag of its own. Its snippet lives in someone
        // else's page and nobody re-pastes it, so the boot load is the only
        // place the instance can tell it whether drawing is offered.
        return $this->json([
            'projectId' => (string) $project->id,
            'drawingEnabled' => $this->featureFlags->isEnabled(SiteReviewDrawing::FLAG, SiteReviewDrawing::DEFAULT),
            // The types the mode "a card for each note, under an epic" can use.
            // Empty hides the mode.
            'parentTypes' => array_map(
                static fn (ParentType $type): array => ['key' => $type->key, 'label' => $type->label],
                $this->parentTypes->forProject($project),
            ),
            'context' => null === $label ? null : ['label' => $label->label, 'url' => $label->url],
            'comments' => array_values(array_map(
                static function (SiteReviewComment $c) use ($links): array {
                    $anchors = array_values($c->anchors->toArray());
                    $link = $links[(string) $c->id] ?? null;

                    return [
                        'id' => (string) $c->id,
                        'body' => $c->body,
                        'url' => $c->url,
                        'surface' => $c->surface->value,
                        'native' => $c->native,
                        'anchors' => array_values(array_map(
                            static fn (SiteReviewCommentAnchor $a): array => [
                                'selector' => $a->selector,
                                'text' => $a->text,
                                'quote' => $a->quote,
                                'quotePrefix' => $a->quotePrefix,
                                'quoteSuffix' => $a->quoteSuffix,
                            ],
                            $anchors,
                        )),
                        'strokes' => $c->strokes ?? [],
                        'link' => null === $link ? null : ['label' => $link->label, 'url' => $link->url],
                    ];
                },
                $view->comments,
            ))]);
    }
}
