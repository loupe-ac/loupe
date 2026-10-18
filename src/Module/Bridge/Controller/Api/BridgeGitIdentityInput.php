<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Utils\GitHubLogin;
use Symfony\Component\Validator\Constraints as Assert;

/** The git identity that one followed project gets on the machine of a bridge. */
final class BridgeGitIdentityInput
{
    public const int MAX_COMMIT_NAME_LENGTH = 255;

    public const array MODES = ['agent_account', 'app_token', 'machine'];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $projectId = null,

        #[Assert\Choice(choices: self::MODES)]
        #[Assert\NotBlank]
        public ?string $mode = null,

        #[Assert\Length(max: self::MAX_COMMIT_NAME_LENGTH)]
        public ?string $commitName = null,

        #[Assert\Length(max: GitHubLogin::MAX_LENGTH)]
        #[Assert\Regex(pattern: GitHubLogin::PATTERN)]
        public ?string $pushLogin = null,

        #[Assert\Positive]
        public ?int $githubUserId = null,
    ) {
    }
}
