<?php

declare(strict_types=1);

namespace App\Module\Artifact\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Artifact\Command\ListArtifactPageCommand;
use App\Module\Artifact\Command\ListArtifactPageHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Lists the artifacts of the token's project, newest first.
 *
 * @phpstan-import-type ArtifactListPayload from ListArtifactPageHandler
 */
#[McpTool(name: 'artifact_list', description: 'List the stored design artifacts of the token\'s project, newest first. Each row has its name, its latest version number, its archive state and where it came from. Read an artifact\'s files with artifact_get, then artifact_read. Archived artifacts are omitted unless includeArchived is true. Paginated: pass page to walk further, and keep going while hasMore is true.')]
final readonly class ArtifactListTool implements FlagGatedToolInterface
{
    public const int DEFAULT_PER_PAGE = 50;

    public function __construct(
        private ArtifactSubjectResolver $subjects,
        private ListArtifactPageHandler $handler,
    ) {
    }

    /**
     * @param int  $page            The 1-based page to read
     * @param int  $perPage         How many artifacts to return per page
     * @param bool $includeArchived Include archived artifacts, which are omitted by default
     *
     * @return ArtifactListPayload
     */
    public function __invoke(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, bool $includeArchived = false): array
    {
        try {
            return ($this->handler)(new ListArtifactPageCommand($this->subjects->requireProject(), $page, $perPage, $includeArchived));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The artifact list could not be read. The error has been logged.', previous: $e);
        }
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return 'artifact_list';
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return ArtifactInstallFlags::FLAG_ENABLED;
    }
}
