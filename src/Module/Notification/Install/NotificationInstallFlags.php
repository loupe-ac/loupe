<?php

declare(strict_types=1);

namespace App\Module\Notification\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Notification\Service\PushAvailability;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class NotificationInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        // Off: the operator sets the key pair first, then opts in.
        yield new InstallFlagDefault(PushAvailability::FLAG, FeatureFlagType::Bool, false);
    }
}
