<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class SkipProjectSetupCommand
{
    public function __construct(
        public Project $project,
        public SessionInterface $session,
    ) {
    }
}
