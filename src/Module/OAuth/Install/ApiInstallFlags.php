<?php

declare(strict_types=1);

namespace App\Module\OAuth\Install;

use App\Api\RateLimit\ApiBudget;
use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** Seeds the two request budgets of the public API. Neither one switches the API off. */
final readonly class ApiInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(ApiBudget::READS_FLAG, FeatureFlagType::Int, ApiBudget::DEFAULT_READS);
        yield new InstallFlagDefault(ApiBudget::WRITES_FLAG, FeatureFlagType::Int, ApiBudget::DEFAULT_WRITES);
    }
}
