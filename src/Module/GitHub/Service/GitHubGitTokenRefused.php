<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** The App cannot hand a project a push token. The cause tells the owner what to fix. */
final class GitHubGitTokenRefused extends \RuntimeException
{
    public const string NO_INSTALLATION = 'no_installation';
    public const string INSTALLATION_SUSPENDED = 'installation_suspended';
    public const string NO_REPOSITORY = 'no_repository';
    public const string MISSING_GRANT = 'missing_grant';
    public const string GITHUB_UNAVAILABLE = 'github_unavailable';

    /**
     * @param self::*      $cause
     * @param list<string> $missingGrants the permission names the installation lacks, for a `missing_grant` cause
     */
    public function __construct(
        public readonly string $cause,
        public readonly array $missingGrants = [],
    ) {
        parent::__construct($cause);
    }
}
