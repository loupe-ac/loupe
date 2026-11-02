<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\PageLinks;
use App\Module\Board\Mcp\CardPayload;

/**
 * The card answer of the public API. The MCP tools read the same payload
 * without links, so only this layer adds them.
 *
 * @phpstan-import-type CardSummary from CardPayload
 */
final readonly class PublicCardAnswer
{
    public function __construct(
        private PageLinks $links,
    ) {
    }

    /**
     * @param CardSummary $card
     *
     * @return array<string, mixed>
     */
    public function card(array $card, string $projectId): array
    {
        return $this->row($card, $projectId, $card['cardId']);
    }

    /**
     * A row can hold any part of the card, because `fields` names what it holds, so the id comes
     * from the caller, and each linked set gets its links only when the row holds that set.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function row(array $row, string $projectId, string $cardId): array
    {
        $reference = fn (array $entry): array => $this->withUrl($entry, 'cardId', $this->links->card($projectId, $entry['cardId']));
        $linkers = [
            'documents' => fn (array $entry): array => $this->withUrl($entry, 'documentId', $this->links->document($projectId, $entry['documentId'])),
            'artifacts' => function (array $entry) use ($projectId): array {
                unset($entry['path']);

                return $this->withUrl($entry, 'artifactId', $this->links->artifact($projectId, $entry['artifactId']));
            },
            'siteReviewComments' => fn (array $entry): array => $this->feedback($entry, $projectId, $cardId),
            'relatedCards' => $reference,
            'children' => $reference,
        ];

        foreach (array_intersect_key($linkers, $row) as $set => $linker) {
            $row[$set] = array_map($linker, $row[$set]);
        }
        if (isset($row['parent'])) {
            $row['parent'] = $reference($row['parent']);
        }

        return $this->withUrl($row, 'cardId', $this->links->card($projectId, $cardId));
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function feedback(array $entry, string $projectId, string $cardId): array
    {
        $pageUrl = $entry['url'];
        unset($entry['url']);

        return ['id' => $entry['id'], 'url' => $this->links->feedback($projectId, $cardId, $entry['id']), 'pageUrl' => $pageUrl] + $entry;
    }

    /**
     * Puts the link right after the id key, or first when the row does not hold that key.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withUrl(array $row, string $idKey, string $url): array
    {
        return array_intersect_key($row, [$idKey => true]) + ['url' => $url] + $row;
    }
}
