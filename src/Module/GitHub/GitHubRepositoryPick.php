<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/** One ticked repository of the picker, as the owner's browser posts it. The handler trusts none of it and asks GitHub again. */
final readonly class GitHubRepositoryPick
{
    /** @param non-empty-string $path */
    public function __construct(
        public int $installationId,
        public int $repositoryId,
        public string $path,
    ) {
    }

    public static function parse(string $value): ?self
    {
        if (1 !== preg_match('#^([1-9]\d{0,18}):([1-9]\d{0,18}):([A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100})$#', $value, $match)) {
            return null;
        }

        return new self((int) $match[1], (int) $match[2], $match[3]);
    }
}
