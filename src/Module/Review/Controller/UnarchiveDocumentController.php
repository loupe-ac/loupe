<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\UnarchiveDocumentCommand;
use App\Module\Review\Command\UnarchiveDocumentHandler;
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
    '/projects/{projectId}/documents/{documentId}/unarchive',
    name: 'app_document_unarchive',
    methods: ['POST'],
)]
final class UnarchiveDocumentController extends AppController
{
    public function __construct(
        private readonly UnarchiveDocumentHandler $unarchiveDocument,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $form = $this->formFactory->createNamed(
            ReviewExtension::archiveFormName($document),
            ArchiveDocumentFormType::class,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ($this->unarchiveDocument)(new UnarchiveDocumentCommand($document));

            $severity = 'success';
            $message = $this->translator->trans('review.archive.flash.unarchived', ['%title%' => $document->title]);
        } else {
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
