<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Api\Problem\ApiProblemException;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[AsTargetedValueResolver(self::NAME)]
final readonly class ReachableProjectValueResolver implements ValueResolverInterface
{
    public const string NAME = 'reachable_project';

    public function __construct(
        private ProjectRepository $projects,
        private TokenStorageInterface $tokenStorage,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return list<Project>
     *
     * @throws ApiProblemException `project_not_found` for a handle that names no project the credential may view
     */
    #[\Override]
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        return [$this->projectOf($request, $argument->getName())];
    }

    /**
     * The project that a path segment names, for a resolver of a child entity that must stay in it.
     *
     * @throws ApiProblemException `project_not_found` for a handle that names no project the credential may view
     */
    public function projectOf(Request $request, string $segment): Project
    {
        $handle = $request->attributes->get($segment);
        $user = $this->tokenStorage->getToken()?->getUser();
        $project = \is_string($handle) && $user instanceof User ? $this->projects->findOneByIdOrSlugForOwner($handle, $user) : null;

        if (null === $project || !$this->authorization->isGranted(ProjectVoter::VIEW, $project)) {
            throw ApiProblemException::projectNotFound();
        }

        return $project;
    }
}
