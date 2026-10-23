<?php

declare(strict_types=1);

namespace App\Module\Project\Controller\Api;

use App\Module\Project\Entity\Project;
use App\Module\Project\Service\SiteOrigins;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
        public ?string $domain = null,

        #[Assert\Length(max: 500, normalizer: 'trim')]
        #[Assert\Regex(pattern: self::REMOTE_PATTERN, normalizer: 'trim')]
        public ?string $remote = null,
        /** @var array<mixed> */
        public array $origins = [],
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

    public function domain(): ?string
    {
        $domain = trim($this->domain ?? '');

        return '' === $domain ? null : $domain;
    }

    /**
     * The origins to allow, normalised, without blanks or duplicates. Call it after validation passed.
     *
     * @return list<string>
     */
    public function origins(): array
    {
        return SiteOrigins::normaliseList(array_values(array_filter($this->origins, \is_string(...)))) ?? [];
    }

    #[Assert\Callback]
    public function validateOrigins(ExecutionContextInterface $context): void
    {
        $strings = [];
        $refused = false;
        foreach ($this->origins as $index => $value) {
            if (\is_string($value) && '' === trim($value)) {
                continue;
            }

            if (!\is_string($value) || null === SiteOrigins::normalisePattern($value)) {
                $context->buildViolation('project.api.error.origin_invalid')->atPath(\sprintf('origins[%s]', $index))->addViolation();
                $refused = true;
                continue;
            }

            $strings[] = $value;
        }

        if (!$refused && \count(SiteOrigins::normaliseList($strings) ?? []) > SiteOrigins::MAX) {
            $context->buildViolation('project.api.error.origins_too_many')->setParameter('{{ limit }}', (string) SiteOrigins::MAX)->atPath('origins')->addViolation();
        }
    }
}
