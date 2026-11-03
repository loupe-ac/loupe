<?php

declare(strict_types=1);

namespace App\Api;

use App\Routing\PinnedUrlGenerator;

/**
 * The absolute link to the app page of each resource, on the configured public
 * address. It is the one place in the public API that names the page routes.
 */
final readonly class PageLinks
{
    public function __construct(
        private PinnedUrlGenerator $urls,
    ) {
    }

    public function project(string $projectId): string
    {
        return $this->urls->generate('app_project_workshop', ['id' => $projectId]);
    }

    public function card(string $projectId, string $cardId): string
    {
        return $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $cardId]);
    }

    public function document(string $projectId, string $documentId): string
    {
        return $this->urls->generate('app_document_review', ['projectId' => $projectId, 'documentId' => $documentId]);
    }

    public function artifact(string $projectId, string $artifactId): string
    {
        return $this->urls->generate('app_project_artifact', ['id' => $projectId, 'artifactId' => $artifactId]);
    }

    public function artifactVersion(string $projectId, string $artifactId, int $number): string
    {
        return $this->urls->generate('app_project_artifact_version', ['id' => $projectId, 'artifactId' => $artifactId, 'number' => $number]);
    }

    /** The Feedback tab of the card, scrolled to the note. Null for a note with no card. */
    public function feedback(string $projectId, ?string $cardId, string $feedbackId): ?string
    {
        if (null === $cardId) {
            return null;
        }

        return $this->urls->generate('app_board_card', [
            'projectId' => $projectId,
            'cardId' => $cardId,
            'tab' => 'feedback',
            '_fragment' => 'feedback-'.$feedbackId,
        ]);
    }
}
