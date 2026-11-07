<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** What a read of `.loupe.yaml` at the head of the default branch found. */
final readonly class GitHubWorkflowFile
{
    /**
     * @param string|null $content null when the file is missing, is not a file, or is too large to trust
     */
    public function __construct(
        public string $headSha,
        public ?string $blobSha,
        public ?string $content,
        public string $defaultBranch,
        public bool $exists = true,
    ) {
    }

    public static function missing(string $headSha, string $defaultBranch): self
    {
        return new self($headSha, null, null, $defaultBranch, exists: false);
    }

    /** The path holds something, but not a file that Loupe can read and write back. */
    public static function unusable(string $headSha, string $defaultBranch): self
    {
        return new self($headSha, null, null, $defaultBranch);
    }
}
