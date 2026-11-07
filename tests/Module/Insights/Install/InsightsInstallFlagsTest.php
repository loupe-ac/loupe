<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Install\InsightsInstallFlags;
use App\Module\Insights\Service\InsightsRefreshSettings;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class InsightsInstallFlagsTest extends TestCase
{
    public function test_public_links_are_on_by_default(): void
    {
        $defaults = [];
        foreach (new InsightsInstallFlags()->defaults(new SeedInstallFlagsCommand(10, true, false, 0, null, false, false)) as $default) {
            $defaults[$default->name] = $default;
        }

        self::assertArrayHasKey(PublicLink::FLAG, $defaults);
        self::assertSame(FeatureFlagType::Bool, $defaults[PublicLink::FLAG]->type);
        self::assertTrue($defaults[PublicLink::FLAG]->value);
    }

    public function test_the_slow_notice_waits_thirty_seconds_by_default(): void
    {
        $defaults = [];
        foreach (new InsightsInstallFlags()->defaults(new SeedInstallFlagsCommand(10, true, false, 0, null, false, false)) as $default) {
            $defaults[$default->name] = $default;
        }

        self::assertArrayHasKey(InsightsRefreshSettings::SLOW_NOTICE_FLAG, $defaults);
        self::assertSame(FeatureFlagType::Int, $defaults[InsightsRefreshSettings::SLOW_NOTICE_FLAG]->type);
        self::assertSame(30, $defaults[InsightsRefreshSettings::SLOW_NOTICE_FLAG]->value);
    }
}
