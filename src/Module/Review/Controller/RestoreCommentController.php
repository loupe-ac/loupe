<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Review\Command\ListVersionCommentsCommand;
use App\Module\Review\Command\ListVersionCommentsHandler;
use App\Module\Review\Command\RestoreCommentCommand;
use App\Module\Review\Command\RestoreCommentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Form\CommentRecoveryFormType;
use App\Module\Review\Form\CommentRecoveryRequest;
use App\Module\Review\Security\CommentVoter;
use App\Module\Review\Twig\ReviewExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(CommentVoter::RESTORE, subject: 'comment')]
#[Route(
    '/comments/{id:comment}/restore',
    name: 'app_comment_restore',
    methods: ['POST'],
)]
final class RestoreCommentController extends AppController
{
    public function __construct(
        private readonly RestoreCommentHandler $restoreComment,
        private readonly ListVersionCommentsHandler $listVersionComments,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Comment $comment, Request $request): Response
    {
        $commentId = (string) $comment->id;
        $parameters = [
            'projectId' => (string) $comment->version->document->project->id,
            'documentId' => (string) $comment->version->document->id,
        ];
        $data = new CommentRecoveryRequest();
        $form = $this->formFactory->createNamed(
            ReviewExtension::recoveryFormName($comment, 'restore'),
            CommentRecoveryFormType::class,
            $data,
            ['action' => $this->generateUrl('app_comment_restore', ['id' => $commentId]), 'method' => 'POST'],
        );
        $form->handleRequest($request);
        $errors = ['deletionSequence' => 'comment.error.stale_deletion'];

        if ($form->isSubmitted() && $form->isValid() && null !== $data->deletionSequence) {
            try {
                ($this->restoreComment)(new RestoreCommentCommand($comment, $data->deletionSequence));
                $message = $this->translator->trans('review.deleted_threads.flash.restored');
                if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $html = $this->renderView('@Review/_comment_added.stream.html.twig', [
                        'comments' => ($this->listVersionComments)(new ListVersionCommentsCommand($comment->version))->comments,
                    ]);
                    $html .= $this->renderView('@Review/_comment_restored.stream.html.twig', ['severity' => 'success', 'message' => $message]);

                    return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
                }

                $this->addFlash('success', $message);

                return $this->redirectToRoute('app_document_review_version', [
                    ...$parameters,
                    'versionNumber' => $comment->version->versionNumber,
                    '_fragment' => 'comment-thread-'.$commentId,
                ]);
            } catch (DomainErrors $e) {
                $errors = $e->errors;
            }
        }

        // No page renders this form, so a bound 422 re-render has nowhere to go.
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $html = '';
            foreach ($errors as $translationKey) {
                $html .= $this->renderView('_flash.stream.html.twig', ['severity' => 'error', 'message' => $this->translator->trans($translationKey)]);
            }

            return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        foreach ($errors as $translationKey) {
            $this->addFlash('error', $this->translator->trans($translationKey));
        }

        return $this->redirectToRoute('app_document_review_version', [
            ...$parameters,
            'versionNumber' => $comment->version->versionNumber,
        ]);
    }
}
