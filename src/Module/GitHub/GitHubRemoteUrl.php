<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/** Reads the repository path out of a git remote URL, for the two shapes a GitHub clone gives. */
final readonly class GitHubRemoteUrl
{
    private const string PATTERN = '#^(?:https://github\.com/|git@github\.com:)([A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)/([A-Za-z0-9._-]+?)(?:\.git)?$#D';

    /** @return ?string `owner/repo`, or null for any other shape */
    public static function pathOf(string $url): ?string
    {
        if (1 !== preg_match(self::PATTERN, $url, $matches) || \in_array($matches[2], ['.', '..', '.git'], true)) {
            return null;
        }

        return $matches[1].'/'.$matches[2];
    }
}
