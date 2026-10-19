<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The repository a managed worker asks a push token for. */
final class IssueGitTokenRequest
{
    public function __construct(
        /** The `owner/name` of the repository, as the worker's git remote names it. */
        #[Assert\Length(max: 255)]
        #[Assert\NotBlank]
        #[Assert\Regex('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#')]
        public ?string $repository = null,
    ) {
    }
}
