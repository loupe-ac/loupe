<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\EventListener;

use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\Project\Event\ProjectDeleting;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Runs inside ProjectDeleter's transaction, with no entity hydration. */
#[AsEventListener]
final readonly class DeleteOpenRouterKeyOnProjectDeleting
{
    public function __construct(
        private OpenRouterKeyRepository $openRouterKeys,
    ) {
    }

    public function __invoke(ProjectDeleting $event): void
    {
        $this->openRouterKeys->deleteForProject($event->project->requireId());
    }
}
