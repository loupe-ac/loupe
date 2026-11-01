<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Composer\Semver\Semver;

/** An online bridge that the server refused for one open work request. */
final readonly class RefusedTaker
{
    /** The first CLI release that names an account in a claim. */
    public const string FIRST_NAMING_VERSION = '1.33.0';

    public function __construct(
        public string $label,
        /** The account the refused claim named, or null when it named none. */
        public ?string $account,
        /** The claim named no account and the CLI is older than the first release that does. */
        public bool $versionGap,
    ) {
    }

    /** A version that is not semver, such as the commit sha of a dev build, gives no gap. */
    public static function hasVersionGap(?string $account, string $cliVersion): bool
    {
        if (null !== $account) {
            return false;
        }

        try {
            return Semver::satisfies($cliVersion, '<'.self::FIRST_NAMING_VERSION);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
