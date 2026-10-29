<?php

declare(strict_types=1);

namespace App\Module\OAuth\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class OAuthInstallFlags implements InstallFlagDefaultsInterface
{
    /** Lets a client redeem a replaced refresh token again until it uses the new one. */
    public const string FLAG_REFRESH_REUSE = 'oauth.refresh_reuse.enabled';

    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // On: it repairs a lost login, and the operator can switch off its
        // longer theft window.
        yield new InstallFlagDefault(self::FLAG_REFRESH_REUSE, FeatureFlagType::Bool, true);
    }
}
