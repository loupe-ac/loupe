<?php

declare(strict_types=1);

namespace App\Module\Artifact\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Resolves the artifacts an MCP tool call may act on, rejecting anything
 * outside the project the token is bound to, and refuses every call while the
 * feature is off. The tool list hides the tools, and this check stops a hidden
 * tool that a client still calls.
 */
final readonly class ArtifactSubjectResolver
{
    use ResolvesBoundProject;

    public function __construct(
        private AuthenticatedProjectResolver $projectResolver,
        private ArtifactRepository $artifacts,
        private AuthorizationCheckerInterface $authorization,
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function requireProject(): Project
    {
        $project = $this->requireBoundProject($this->projectResolver);

        if (!$this->featureFlags->isEnabled(ArtifactInstallFlags::FLAG_ENABLED)) {
            throw new ToolCallException('Artifacts are switched off on this instance.');
        }

        return $project;
    }

    /** @param McpBoundProjectVoter::ARTIFACT_* $attribute */
    public function requireArtifact(string $artifactId, string $attribute): Artifact
    {
        $project = $this->requireProject();

        $artifact = $this->artifacts->findOneByIdInProject($artifactId, $project);

        // Identical for "does not exist" and "belongs to another project", so a
        // tool cannot probe what exists outside the token's project.
        if (null === $artifact || !$this->authorization->isGranted($attribute, $artifact)) {
            throw new ToolCallException(\sprintf('Artifact "%s" not found or not accessible.', $artifactId));
        }

        return $artifact;
    }
}
