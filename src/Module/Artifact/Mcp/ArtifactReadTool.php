<?php

declare(strict_types=1);

namespace App\Module\Artifact\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Artifact\Command\ReadArtifactFileCommand;
use App\Module\Artifact\Command\ReadArtifactFileHandler;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;

/**
 * Reads one file of one artifact version. A text file comes back as text and a
 * PNG, JPEG, GIF or WebP image as image content. Any other binary file is
 * refused with its size, because a model cannot read it.
 */
#[McpTool(name: 'artifact_read', description: 'Read one file of a stored design artifact by its path, from the latest version unless versionNumber is given. A text file (HTML, CSS, JavaScript, SVG, JSON, Markdown) is returned as text. A PNG, JPEG, GIF or WebP image is returned as an image. Any other binary file is refused, and the refusal names its size. List the paths with artifact_get.')]
final readonly class ArtifactReadTool implements FlagGatedToolInterface
{
    private const array IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private ArtifactSubjectResolver $subjects,
        private ReadArtifactFileHandler $handler,
    ) {
    }

    /**
     * @param string   $artifactId    The UUID of the artifact
     * @param string   $path          The path of the file inside the version, as artifact_get lists it
     * @param int|null $versionNumber The version to read from. Defaults to the latest.
     */
    public function __invoke(string $artifactId, string $path, ?int $versionNumber = null): TextContent|ImageContent
    {
        try {
            $artifact = $this->subjects->requireArtifact($artifactId, McpBoundProjectVoter::ARTIFACT_READ);

            $file = ($this->handler)(new ReadArtifactFileCommand($artifact, $path, $versionNumber));

            $contents = $file->blob->contents();
            $type = strtolower(trim(explode(';', $file->contentType)[0]));

            if (\in_array($type, self::IMAGE_TYPES, true)) {
                return new ImageContent(base64_encode($contents), $type);
            }

            if (!str_contains($contents, "\0") && mb_check_encoding($contents, 'UTF-8')) {
                return new TextContent($contents);
            }

            throw new ToolCallException(\sprintf('"%s" is a binary file of %d bytes (%s), and only text and PNG, JPEG, GIF or WebP images can be read through MCP. Download it from the artifact page instead.', $path, $file->blob->size, $file->contentType));
        } catch (DomainErrors $e) {
            throw new ToolCallException(isset($e->errors['path']) ? \sprintf('The artifact has no file "%s". List the paths with artifact_get.', $path) : \sprintf('Artifact "%s" has no version %d.', $artifactId, $versionNumber));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The artifact file could not be read. The error has been logged.', previous: $e);
        }
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return 'artifact_read';
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return ArtifactInstallFlags::FLAG_ENABLED;
    }
}
