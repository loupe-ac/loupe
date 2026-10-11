<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestChangeFingerprint;
use App\Module\Forge\Service\PullRequestChangeFingerprintReader;

/** Answers the next fingerprint in line for one forge, `fake` by default, and records the head of each read. */
final class FakePullRequestChangeFingerprintReader implements PullRequestChangeFingerprintReader
{
    /** @var list<string> */
    public array $heads = [];

    /** @param list<?PullRequestChangeFingerprint> $answers */
    public function __construct(
        public array $answers = [],
        private readonly string $forge = FakePullRequestStateReader::FORGE,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return $this->forge === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest, string $headSha): ?PullRequestChangeFingerprint
    {
        $this->heads[] = $headSha;

        return array_shift($this->answers);
    }
}
