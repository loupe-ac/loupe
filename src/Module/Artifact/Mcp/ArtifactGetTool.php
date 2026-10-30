<?php

declare(strict_types=1);

namespace App\Module\Artifact\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Artifact\Command\DescribeArtifactCommand;
use App\Module\Artifact\Command\DescribeArtifactHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Describes one artifact: its versions, and the files of one version.
 *
 * @phpstan-import-type ArtifactPayload from DescribeArtifactHandler
 */
#[McpTool(name: 'artifact_get', description: 'Describe one stored design artifact: its name, archive state, source link and every version with its notes on what changed, its file count and size, plus the files of one version (path, content type, size) and pageLink, a full web address that opens the entry page of that version as the app serves it, valid for one hour. Defaults to the latest version. Read a file\'s content with artifact_read.')]
final readonly class ArtifactGetTool implements FlagGatedToolInterface
{
    public function __construct(
        private ArtifactSubjectResolver $subjects,
        private DescribeArtifactHandler $handler,
    ) {
    }

    /**
     * @param string   $artifactId    The UUID of the artifact, from artifact_list or card_get
     * @param int|null $versionNumber The version whose files to list. Defaults to the latest.
     *
     * @return ArtifactPayload
     */
    public function __invoke(string $artifactId, ?int $versionNumber = null): array
    {
        try {
            $artifact = $this->subjects->requireArtifact($artifactId, McpBoundProjectVoter::ARTIFACT_READ);

            return ($this->handler)(new DescribeArtifactCommand($artifact, $versionNumber));
        } catch (DomainErrors) {
            throw new ToolCallException(\sprintf('Artifact "%s" has no version %d.', $artifactId, $versionNumber));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The artifact could not be read. The error has been logged.', previous: $e);
        }
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return 'artifact_get';
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return ArtifactInstallFlags::FLAG_ENABLED;
    }
}
