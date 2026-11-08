<?php

declare(strict_types=1);

namespace App\Module\Insights\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Service\AnalysisSettings;
use App\Module\Insights\Service\InsightsRefreshSettings;
use App\Module\Insights\Service\SavedGraphCap;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final readonly class InsightsInstallFlags implements InstallFlagDefaultsInterface
{
    #[\Override]
    public function defaults(SeedInstallFlagsCommand $command): iterable
    {
        yield new InstallFlagDefault(AnalysisSettings::MODEL_FLAG, FeatureFlagType::String, AnalysisSettings::DEFAULT_MODEL);
        yield new InstallFlagDefault(AnalysisSettings::EFFORT_FLAG, FeatureFlagType::String, AnalysisSettings::DEFAULT_EFFORT);
        yield new InstallFlagDefault(SavedGraphCap::FLAG, FeatureFlagType::Int, SavedGraphCap::DEFAULT);
        yield new InstallFlagDefault(InsightsRefreshSettings::REFRESH_DELAY_FLAG, FeatureFlagType::Int, InsightsRefreshSettings::REFRESH_DELAY_DEFAULT);
        yield new InstallFlagDefault(InsightsRefreshSettings::SWEEP_INTERVAL_FLAG, FeatureFlagType::Int, InsightsRefreshSettings::SWEEP_INTERVAL_DEFAULT);
        yield new InstallFlagDefault(InsightsRefreshSettings::SLOW_NOTICE_FLAG, FeatureFlagType::Int, InsightsRefreshSettings::SLOW_NOTICE_DEFAULT);
        yield new InstallFlagDefault(InsightsRefreshSettings::DORMANT_DAYS_FLAG, FeatureFlagType::Int, InsightsRefreshSettings::DORMANT_DAYS_DEFAULT);
        yield new InstallFlagDefault(PublicLink::FLAG, FeatureFlagType::Bool, true);
    }
}
