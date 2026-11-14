<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
final readonly class PreviewGitHubInstallChoiceCommand
{
    public function __construct(
        public string $token,
        public int $installationId,
    ) {
    }
}
