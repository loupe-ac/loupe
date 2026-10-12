<?php

declare(strict_types=1);

namespace App\Module\Artifact\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Artifact\Command\UnarchiveArtifactCommand;
use App\Module\Artifact\Command\UnarchiveArtifactHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'artifact_unarchive', description: 'Restore an archived design artifact so artifact_list shows it again. Restoring a live artifact changes nothing.')]
final readonly class ArtifactUnarchiveTool implements FlagGatedToolInterface
{
    public function __construct(
        private ArtifactSubjectResolver $subjects,
        private UnarchiveArtifactHandler $handler,
    ) {
    }

    /**
     * @param string $artifactId The UUID of the artifact to unarchive
     *
     * @return array{artifactId: string, archived: bool}
     */
    public function __invoke(string $artifactId): array
    {
        try {
            $artifact = $this->subjects->requireArtifact($artifactId, McpBoundProjectVoter::ARTIFACT_WRITE);

            ($this->handler)(new UnarchiveArtifactCommand($artifact));

            return ['artifactId' => (string) $artifact->id, 'archived' => null !== $artifact->archivedAt];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The artifact could not be restored. The error has been logged.', previous: $e);
        }
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return 'artifact_unarchive';
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return ArtifactInstallFlags::FLAG_ENABLED;
    }
}
