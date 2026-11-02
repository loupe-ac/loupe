<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Api\Problem\ApiProblemException;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Service\DocumentApiProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Uid\Uuid;

#[AsTargetedValueResolver(self::NAME)]
final readonly class DocumentCommentValueResolver implements ValueResolverInterface
{
    public const string NAME = 'document_comment';

    public function __construct(
        private ProjectDocumentValueResolver $documents,
        private CommentRepository $comments,
    ) {
    }

    /**
     * @return list<Comment>
     *
     * @throws ApiProblemException `project_not_found` and `document_not_found` as the document resolver gives them, `comment_not_found` for a comment outside the document
     */
    #[\Override]
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        [$document] = $this->documents->resolve($request, new ArgumentMetadata('document', Document::class, false, false, null));
        $id = $request->attributes->get($argument->getName());
        $comment = \is_string($id) && Uuid::isValid($id) ? $this->comments->find(Uuid::fromString($id)) : null;

        if (null === $comment || $comment->version->document !== $document) {
            throw DocumentApiProblems::commentNotFound();
        }

        return [$comment];
    }
}
