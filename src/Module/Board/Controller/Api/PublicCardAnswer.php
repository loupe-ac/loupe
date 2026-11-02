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
 * @phpstan-import-type CardListSummary from CardPayload
 */
final readonly class PublicCardAnswer
{
    public function __construct(
        private PageLinks $links,
    ) {
    }

    /**
     * @param CardListSummary $row
     *
     * @return array<string, mixed>
     */
    public function row(array $row, string $projectId): array
    {
        return $this->withUrl($row, 'cardId', $this->links->card($projectId, $row['cardId']));
    }

    /**
     * @param CardSummary $card
     *
     * @return array<string, mixed>
     */
    public function card(array $card, string $projectId): array
    {
        $cardId = $card['cardId'];
        $reference = fn (array $entry): array => $this->withUrl($entry, 'cardId', $this->links->card($projectId, $entry['cardId']));

        return $this->withUrl(array_replace($card, [
            'documents' => array_map(
                fn (array $entry): array => $this->withUrl($entry, 'documentId', $this->links->document($projectId, $entry['documentId'])),
                $card['documents'],
            ),
            'artifacts' => array_map(function (array $entry) use ($projectId): array {
                unset($entry['path']);

                return $this->withUrl($entry, 'artifactId', $this->links->artifact($projectId, $entry['artifactId']));
            }, $card['artifacts']),
            'siteReviewComments' => array_map(
                fn (array $entry): array => $this->feedback($entry, $projectId, $cardId),
                $card['siteReviewComments'],
            ),
            'relatedCards' => array_map($reference, $card['relatedCards']),
            'parent' => null === $card['parent'] ? null : $reference($card['parent']),
            'children' => array_map($reference, $card['children']),
        ]), 'cardId', $this->links->card($projectId, $cardId));
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
     * Puts the link right after the id key.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function withUrl(array $row, string $idKey, string $url): array
    {
        return [$idKey => $row[$idKey], 'url' => $url] + $row;
    }
}
