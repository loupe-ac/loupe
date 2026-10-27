<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/** The private topic that the Repositories pages of a project listen on. */
final readonly class RepositoryCheckTopics
{
    public function __construct(
        #[Autowire(param: 'app.url')]
        private string $appUrl,
    ) {
    }

    public function forProject(Uuid $projectId): string
    {
        return rtrim($this->appUrl, '/').'/projects/'.$projectId.'/repository-checks';
    }
}
