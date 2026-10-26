<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use Mcp\Exception\ToolCallException;

/**
 * Turns a refusal of a decision or verdict tool into the message an agent reads.
 * A stale version names the newer one, so the agent can show it to the owner.
 */
final readonly class DocumentToolErrors
{
    private const array STALE_VERSION_KEYS = [
        'review.decision.error.stale_version',
        'review.document.flash.verdict_stale',
    ];

    public function __construct(
        private ToolCallErrorMessages $messages,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    /**
     * @param array<string, string> $renames the argument name the tool uses, by the field name the handler uses
     */
    public function forDocument(DomainErrors $errors, Document $document, array $renames = []): ToolCallException
    {
        if (\in_array($errors->errors['versionNumber'] ?? null, self::STALE_VERSION_KEYS, true)) {
            $latest = $this->documentVersions->findLatest($document);
            $description = rtrim(trim($latest->description ?? ''), '. ');

            return new ToolCallException(
                \sprintf(
                    'The document has version %d now%s. Show it to the owner, then call again with versionNumber %d.',
                    $latest->versionNumber,
                    '' === $description ? '' : ': '.$description,
                    $latest->versionNumber,
                ),
                previous: $errors,
            );
        }

        $fields = [];
        $parameters = [];
        foreach ($errors->errors as $field => $key) {
            $name = $renames[$field] ?? $field;
            $fields[$name] = $key;
            if (isset($errors->parameters[$field])) {
                $parameters[$name] = $errors->parameters[$field];
            }
        }

        return $this->messages->forAgent(new DomainErrors($fields, $parameters));
    }
}
