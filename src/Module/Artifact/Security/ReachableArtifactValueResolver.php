<?php

declare(strict_types=1);

namespace App\Module\Artifact\Security;

use App\Api\Problem\ApiProblemException;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Artifact\Service\ArtifactApiProblems;
use App\Module\Project\Security\ReachableProjectValueResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[AsTargetedValueResolver(self::NAME)]
final readonly class ReachableArtifactValueResolver implements ValueResolverInterface
{
    public const string NAME = 'reachable_artifact';

    public function __construct(
        private ReachableProjectValueResolver $projects,
        private ArtifactRepository $artifacts,
        private ArtifactApiProblems $problems,
    ) {
    }

    /**
     * @return list<Artifact>
     *
     * @throws ApiProblemException `project_not_found` or `artifact_not_found`
     */
    #[\Override]
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $project = $this->projects->projectOf($request, 'project');
        $id = $request->attributes->get($argument->getName());
        $artifact = \is_string($id) ? $this->artifacts->findOneByIdInProject($id, $project) : null;

        return [$artifact ?? throw $this->problems->artifactNotFound()];
    }
}
