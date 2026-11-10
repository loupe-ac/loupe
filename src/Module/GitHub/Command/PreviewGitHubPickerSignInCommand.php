<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
final readonly class PreviewGitHubPickerSignInCommand
{
    public function __construct(
        public Project $project,
        public string $token,
    ) {
    }
}
