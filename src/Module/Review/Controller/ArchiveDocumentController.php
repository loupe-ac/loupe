<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\ArchiveDocumentFormType;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Twig\ReviewExtension;
use App\Module\Review\View\DocumentListQuery;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(DocumentVoter::MANAGE, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/archive',
    name: 'app_document_archive',
    methods: ['POST'],
)]
final class ArchiveDocumentController extends AppController
{
    public function __construct(
        private readonly ArchiveDocumentHandler $archiveDocument,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        // Rebuilt under the name the list rendered it with, so handleRequest()
        // finds the submission and the form component checks its own CSRF token.
        $form = $this->formFactory->createNamed(
            ReviewExtension::archiveFormName($document),
            ArchiveDocumentFormType::class,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->archiveDocument)(new ArchiveDocumentCommand($document));

            $severity = 'success';
            $message = $this->translator->trans('review.archive.flash.archived', ['%title%' => $document->title]);
        } else {
            // The form has no fields, so a failure is a stale or forged token
            // rather than a mistake the reader could correct. Say so on the list
            // instead of re-rendering a form they never filled in.
            $severity = 'error';
            $message = $this->translator->trans('review.archive.flash.rejected');
        }

        $listParams = DocumentListQuery::fromQuery($request->query)->routeParams();
        $projectId = (string) $document->project->id;

        // The row form posts inside the list frame, and the session flash renders outside it, so the answer carries its own flash and reloads the frame.
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(
                $this->renderView('@Review/_document_archive.stream.html.twig', [
                    'severity' => $severity,
                    'message' => $message,
                    'listUrl' => $this->generateUrl('app_project_documents', ['id' => $projectId, ...$listParams]),
                ]),
                Response::HTTP_OK,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        $this->addFlash($severity, $message);

        return $this->redirectToRoute('app_project_documents', ['id' => $projectId, ...$listParams]);
    }
}
