<?php

declare(strict_types=1);

namespace App\Module\Review\Security;

use App\Api\Problem\ApiProblemException;
use App\Module\Project\Security\ReachableProjectValueResolver;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\DocumentApiProblems;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[AsTargetedValueResolver(self::NAME)]
final readonly class ProjectDocumentValueResolver implements ValueResolverInterface
{
    public const string NAME = 'project_document';

    public function __construct(
        private ReachableProjectValueResolver $projects,
        private DocumentRepository $documents,
    ) {
    }

    /**
     * @return list<Document>
     *
     * @throws ApiProblemException `project_not_found` for a project the credential does not reach, `document_not_found` for a document outside it
     */
    #[\Override]
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $project = $this->projects->projectOf($request, 'project');
        $id = $request->attributes->get($argument->getName());
        $document = \is_string($id) ? $this->documents->findOneByIdAndProjectId($id, (string) $project->id) : null;

        return [$document ?? throw DocumentApiProblems::documentNotFound()];
    }
}
