<?php

declare(strict_types=1);

namespace App\Module\Landing\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Landing\Twig\LandingExtension;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class LandingInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(
            LandingExtension::OPEN_GRAPH_IMAGE_FLAG,
            FeatureFlagType::Select,
            LandingExtension::DEFAULT_OPEN_GRAPH_IMAGE,
            LandingExtension::OPEN_GRAPH_IMAGES,
        );
    }
}
