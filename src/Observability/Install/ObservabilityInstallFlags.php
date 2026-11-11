<?php

declare(strict_types=1);

namespace App\Observability\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Observability\TracesSampler;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class ObservabilityInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(TracesSampler::EVALUATE_CARD_RATE_FLAG, FeatureFlagType::Int, TracesSampler::EVALUATE_CARD_RATE_DEFAULT);
    }
}
