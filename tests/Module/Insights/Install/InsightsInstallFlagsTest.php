<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Install\InsightsInstallFlags;
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
}
