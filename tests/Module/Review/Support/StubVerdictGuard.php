<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Support;

use App\Module\Review\Contract\VerdictGuardInterface;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;

/**
 * Answers every question with one key, and keeps what it was asked. The test container tags one that lets every
 * verdict through until a test gives it a key.
 */
final class StubVerdictGuard implements VerdictGuardInterface
{
    /** @var list<array{string, Verdict}> the document id, because a failure that prints an entity exhausts the memory */
    public array $calls = [];

    public function __construct(
        public ?string $key = null,
    ) {
    }

    #[\Override]
    public function refusal(Document $document, Verdict $verdict): ?string
    {
        $this->calls[] = [(string) $document->id, $verdict];

        return $this->key;
    }
}
