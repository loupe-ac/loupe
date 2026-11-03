<?php

declare(strict_types=1);

namespace App\Module\Review\Twig;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\ArchiveDocumentFormType;
use App\Module\Review\Form\CommentRecoveryFormType;
use App\Module\Review\Form\CommentRecoveryRequest;
use App\Module\Review\Form\ReplyFormType;
use App\Module\Review\Form\ReplyRequest;
use App\Module\Review\Service\AgentReviewSummary;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Builds the per-thread reply form. A thread renders one reply form each, so the
 * form is created here (per the "form per item in a loop" convention) rather than
 * an array of forms in the controller. Each form is named after its comment so the
 * rendered field ids/names don't collide across threads.
 */
final class ReviewExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AgentReviewSummary $agentReviewSummary,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('comment_reply_form', $this->commentReplyForm(...)),
            new TwigFunction('comment_author_label', $this->commentAuthorLabel(...)),
            new TwigFunction('document_archive_form', $this->documentArchiveForm(...)),
            new TwigFunction('comment_recovery_form', $this->commentRecoveryForm(...)),
            new TwigFunction('agent_review_summary', $this->agentReviewSummary->forDocument(...)),
        ];
    }

    /** The author's name, or the key and its owner when a key wrote the comment. */
    public function commentAuthorLabel(Comment $comment): string
    {
        if (null === $comment->apiKeyId) {
            return $comment->author->fullName;
        }

        return null === $comment->apiKeyName
            ? $this->translator->trans('review.document.comment.author_unnamed_api_key', ['%owner%' => $comment->author->fullName])
            : $this->translator->trans('review.document.comment.author_api_key', ['%name%' => $comment->apiKeyName, '%owner%' => $comment->author->fullName]);
    }

    public function commentReplyForm(Comment $comment): FormView
    {
        return $this->formFactory->createNamed(
            self::replyFormName($comment),
            ReplyFormType::class,
            new ReplyRequest(),
            [
                'action' => $this->urlGenerator->generate('app_comment_reply', ['id' => (string) $comment->id]),
                'method' => 'POST',
            ],
        )->createView();
    }

    /**
     * Stable form name shared by the rendered form (here) and the controller that
     * binds the submission, so handleRequest() reads the right request key.
     */
    public static function replyFormName(Comment $comment): string
    {
        return 'reply_'.$comment->id?->toBase32();
    }

    /** @param 'restore' $action */
    public function commentRecoveryForm(Comment $comment, string $action): FormView
    {
        return $this->formFactory->createNamed(
            self::recoveryFormName($comment, $action),
            CommentRecoveryFormType::class,
            new CommentRecoveryRequest($comment->deletionSequence),
            [
                'action' => $this->urlGenerator->generate('app_comment_'.$action, ['id' => (string) $comment->id]),
                'method' => 'POST',
            ],
        )->createView();
    }

    /** @param 'restore' $action */
    public static function recoveryFormName(Comment $comment, string $action): string
    {
        return $action.'_comment_'.$comment->id?->toBase32();
    }

    /**
     * The archive control for one row of the documents list, pointed at whichever
     * of the two routes the document's current state calls for.
     *
     * The list state rides on the action URL so the redirect afterwards returns
     * to the page and filter the reader submitted from.
     *
     * @param array<string, int|string> $listParams
     */
    public function documentArchiveForm(Document $document, array $listParams = []): FormView
    {
        $route = null === $document->archivedAt ? 'app_document_archive' : 'app_document_unarchive';

        return $this->formFactory->createNamed(
            self::archiveFormName($document),
            ArchiveDocumentFormType::class,
            null,
            [
                'action' => $this->urlGenerator->generate($route, [
                    'projectId' => (string) $document->project->id,
                    'documentId' => (string) $document->id,
                    ...$listParams,
                ]),
                'method' => 'POST',
            ],
        )->createView();
    }

    /**
     * One name per document rather than one for the whole list: the rendered
     * token input would otherwise repeat the same DOM id on every row.
     */
    public static function archiveFormName(Document $document): string
    {
        return 'archive_document_'.$document->id?->toBase32();
    }
}
