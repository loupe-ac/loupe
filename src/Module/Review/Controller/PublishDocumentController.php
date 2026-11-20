<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\PublishDocumentCommand;
use App\Module\Review\Command\PublishDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\DocumentStateStreams;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('document-publish')]
#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/publish',
    name: 'app_document_publish',
    methods: ['POST'],
)]
final class PublishDocumentController extends AppController
{
    public function __construct(
        private readonly PublishDocumentHandler $publishDocument,
        private readonly DocumentStateStreams $streams,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $severity = 'success';
        $message = null;
        try {
            if (($this->publishDocument)(new PublishDocumentCommand($document))) {
                $message = $this->translator->trans('review.publish.flash.published', ['%title%' => $document->title]);
            }
        } catch (DomainErrors $e) {
            $severity = 'error';
            $message = $this->translator->trans(array_first($e->errors));
        }

        $user = $this->getUser();
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat() && $user instanceof User) {
            return $this->streams->answer($document, $user, $severity, $message);
        }

        if (null !== $message) {
            $this->addFlash($severity, $message);
        }

        return $this->redirectToRoute('app_document_review', [
            'projectId' => (string) $document->project->id,
            'documentId' => (string) $document->id,
        ]);
    }
}
