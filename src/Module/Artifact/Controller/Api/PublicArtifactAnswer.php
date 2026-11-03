<?php

declare(strict_types=1);

namespace App\Module\Artifact\Controller\Api;

use App\Api\PageLinks;
use App\Module\Artifact\Command\DescribeArtifactHandler;

/**
 * The artifact answer of the public API. The MCP tool reads the same payload
 * with its path, so only this layer swaps the path for the whole link.
 *
 * @phpstan-import-type ArtifactPayload from DescribeArtifactHandler
 */
final readonly class PublicArtifactAnswer
{
    public function __construct(
        private PageLinks $links,
    ) {
    }

    /**
     * @param ArtifactPayload $payload
     *
     * @return array<string, mixed>
     */
    public function of(array $payload, string $projectId): array
    {
        $answer = [];
        foreach ($payload as $key => $value) {
            if ('path' === $key) {
                $answer['url'] = $this->links->artifact($projectId, $payload['artifactId']);

                continue;
            }

            $answer[$key] = $value;
        }

        return $answer;
    }
}
