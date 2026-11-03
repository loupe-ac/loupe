<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One skill package a bridge installed for a worker, and whether the install worked. */
final class BridgeSkillInput
{
    public const string PACKAGE_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/D';

    public const int MAX_PACKAGE_LENGTH = 100;

    public const int MAX_VERSION_LENGTH = 50;

    public const int MAX_REASON_LENGTH = 500;

    public const string STATE_CURRENT = 'current';

    public const string STATE_FAILED = 'failed';

    public function __construct(
        #[Assert\Length(max: self::MAX_PACKAGE_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Regex(pattern: self::PACKAGE_PATTERN, normalizer: 'trim')]
        public ?string $package = null,

        #[Assert\Length(max: self::MAX_VERSION_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $version = null,

        #[Assert\Choice(choices: [self::STATE_CURRENT, self::STATE_FAILED])]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\Length(max: self::MAX_REASON_LENGTH, normalizer: 'trim')]
        public ?string $reason = null,
    ) {
    }
}
