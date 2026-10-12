<?php

declare(strict_types=1);

namespace App\Module\Artifact\Service;

use App\Module\Artifact\Install\ArtifactInstallFlags;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * The sites a stored page may load fonts, scripts and data from. The flag is
 * free text, and its tokens end up in a CSP header, so only a plain https
 * origin passes. Anything else is dropped, never repaired.
 */
final readonly class ArtifactAllowedOrigins
{
    private const string ORIGIN_PATTERN = '#^https://(?:\*\.)?(?:[a-z0-9-]+\.)+[a-z0-9-]+(?::[0-9]{1,5})?$#';

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    /** @return list<string> */
    public function origins(): array
    {
        return self::parse($this->featureFlags->getStringValue(
            ArtifactInstallFlags::FLAG_ALLOWED_ORIGINS,
            ArtifactInstallFlags::DEFAULT_ALLOWED_ORIGINS,
        ));
    }

    /** @return list<string> */
    public static function parse(string $value): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = array_filter($tokens, static fn (string $token): bool => 1 === preg_match(self::ORIGIN_PATTERN, $token));

        return array_values(array_unique($valid));
    }
}
