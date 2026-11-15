<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Review\Event\CommentThreadScope;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open review pages of a document, and the open documents lists of
 * its project, that something changed. A message names ids, a version number
 * and a scope, never text, so each page reads the new state itself.
 */
final readonly class DocumentLiveSignals
{
    public const string VERSION_PUBLISHED = 'review.version_published';
    public const string THREAD_CHANGED = 'review.thread_changed';
    public const string DOCUMENT_STATE_CHANGED = 'review.document_state_changed';
    public const string DOCUMENTS_CHANGED = 'documents.changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    public function versionPublished(Uuid $projectId, Uuid $documentId, int $versionNumber): void
    {
        $this->publisher->queue($this->topics->forDocument($projectId, $documentId), [
            'type' => self::VERSION_PUBLISHED,
            'documentId' => (string) $documentId,
            'versionNumber' => $versionNumber,
        ]);
    }

    public function threadChanged(Uuid $projectId, Uuid $documentId, Uuid $commentId, CommentThreadScope $scope): void
    {
        $this->publisher->queue($this->topics->forDocument($projectId, $documentId), [
            'type' => self::THREAD_CHANGED,
            'documentId' => (string) $documentId,
            'commentId' => (string) $commentId,
            'scope' => $scope->value,
        ]);
    }

    public function documentStateChanged(Uuid $projectId, Uuid $documentId): void
    {
        $this->publisher->queue($this->topics->forDocument($projectId, $documentId), [
            'type' => self::DOCUMENT_STATE_CHANGED,
            'documentId' => (string) $documentId,
        ]);
    }

    /** The list names the document when one changed, and none when a series did. */
    public function documentsChanged(Uuid $projectId, ?Uuid $documentId): void
    {
        $this->publisher->queue($this->topics->forDocuments($projectId), [
            'type' => self::DOCUMENTS_CHANGED,
            'documentId' => null === $documentId ? null : (string) $documentId,
        ]);
    }
}
