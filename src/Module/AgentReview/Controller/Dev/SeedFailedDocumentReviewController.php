<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Controller\Dev;

use App\Controller\AppController;
use App\Module\AgentReview\Command\SeedFailedDocumentReviewCommand;
use App\Module\AgentReview\Command\SeedFailedDocumentReviewHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Review\Entity\Document;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Dev-only: stores a failed agent review of a draft design, which starts its next round. */
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/dev/seed/document-agent-review/{projectId}/{documentId}',
    name: 'app_dev_seed_document_agent_review',
    requirements: ['projectId' => Requirement::UUID, 'documentId' => Requirement::UUID],
    methods: ['POST'],
)]
#[When('dev')]
final class SeedFailedDocumentReviewController extends AppController
{
    public function __construct(
        private readonly SeedFailedDocumentReviewHandler $seedReview,
    ) {
    }

    public function __invoke(
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): JsonResponse {
        $review = ($this->seedReview)(new SeedFailedDocumentReviewCommand($document)) ?? throw $this->createNotFoundException();

        return $this->json(['reviewId' => (string) $review->id], JsonResponse::HTTP_CREATED);
    }
}
