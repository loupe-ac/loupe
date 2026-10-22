<?php

declare(strict_types=1);

namespace App\Site\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Site\SiteLinks;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** All three links seed empty. The source link then follows APP_SOURCE_URL until an admin sets it. */
final readonly class SiteLinksInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(SiteLinks::DISCORD_FLAG, FeatureFlagType::String, '');
        yield new InstallFlagDefault(SiteLinks::X_FLAG, FeatureFlagType::String, '');
        yield new InstallFlagDefault(SiteLinks::SOURCE_FLAG, FeatureFlagType::String, '');
    }
}
