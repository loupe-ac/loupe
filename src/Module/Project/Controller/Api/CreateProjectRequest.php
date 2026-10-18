<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Module\Project\Entity\Project;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A request to create a project from the CLI. Give a name, a repository
 * remote, or both. A request with a remote and no name takes the name of the
 * repository.
 */
final class CreateProjectRequest
{
    private const string REMOTE_PATTERN = '~^(?:(?:https?|ssh|git)://(?:[^@/\s]+@)?[^/\s:]+(?::\d+)?/|[^@/\s]+@[^/\s:]+:)(?<path>[^\s]+?)(?:\.git)?/?$~';

    public function __construct(
        #[Assert\Length(max: Project::MAX_NAME_LENGTH, normalizer: 'trim')]
        public ?string $name = null,

        #[Assert\Length(max: Project::MAX_DOMAIN_LENGTH, normalizer: 'trim')]
        public ?string $website = null,

        #[Assert\Length(max: 500, normalizer: 'trim')]
        #[Assert\Regex(pattern: self::REMOTE_PATTERN, normalizer: 'trim')]
        public ?string $remote = null,
    ) {
    }

    /** The name the project gets: the given one, else the last path segment of the remote. */
    public function name(): string
    {
        $name = trim($this->name ?? '');
        if ('' !== $name) {
            return $name;
        }

        $remote = trim($this->remote ?? '');
        if (1 !== preg_match(self::REMOTE_PATTERN, $remote, $matches)) {
            return '';
        }

        return mb_substr(basename($matches['path']), 0, Project::MAX_NAME_LENGTH);
    }

    public function website(): ?string
    {
        $website = trim($this->website ?? '');

        return '' === $website ? null : $website;
    }
}
