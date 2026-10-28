<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/** The private topic that the Repositories pages of a project listen on. */
final readonly class RepositoryCheckTopics
{
    private const string SUFFIX = '/repository-checks';

    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function forProject(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.self::SUFFIX;
    }

    /** The project that a forProject() topic names, or null for any other string. */
    public function projectIdFromTopic(string $topic): ?Uuid
    {
        $prefix = rtrim($this->appUrl, '/').'/projects/';
        if (!str_starts_with($topic, $prefix) || !str_ends_with($topic, self::SUFFIX)) {
            return null;
        }

        $projectId = substr($topic, \strlen($prefix), -\strlen(self::SUFFIX));

        return Uuid::isValid($projectId) ? Uuid::fromString($projectId) : null;
    }
}
