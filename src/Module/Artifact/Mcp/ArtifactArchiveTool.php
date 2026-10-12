<?php

declare(strict_types=1);

namespace App\Module\Artifact\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Artifact\Command\ArchiveArtifactCommand;
use App\Module\Artifact\Command\ArchiveArtifactHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'artifact_archive', description: 'Archive a stored design artifact. It disappears from artifact_list unless includeArchived is true, and its versions and files stay readable. Nothing is deleted. Archiving is curation, so use it only when asked. Archiving an archived artifact changes nothing.')]
final readonly class ArtifactArchiveTool implements FlagGatedToolInterface
{
    public function __construct(
        private ArtifactSubjectResolver $subjects,
        private ArchiveArtifactHandler $handler,
    ) {
    }

    /**
     * @param string $artifactId The UUID of the artifact to archive
     *
     * @return array{artifactId: string, archived: bool}
     */
    public function __invoke(string $artifactId): array
    {
        try {
            $artifact = $this->subjects->requireArtifact($artifactId, McpBoundProjectVoter::ARTIFACT_WRITE);

            ($this->handler)(new ArchiveArtifactCommand($artifact));

            return ['artifactId' => (string) $artifact->id, 'archived' => null !== $artifact->archivedAt];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The artifact could not be archived. The error has been logged.', previous: $e);
        }
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return 'artifact_archive';
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return ArtifactInstallFlags::FLAG_ENABLED;
    }
}
