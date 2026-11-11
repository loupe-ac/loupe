<?php

declare(strict_types=1);

namespace App\Tests\Observability\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Observability\Install\ObservabilityInstallFlags;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class ObservabilityInstallFlagsTest extends TestCase
{
    public function test_a_fresh_install_traces_one_card_evaluation_in_a_hundred(): void
    {
        $command = new \ReflectionClass(SeedInstallFlagsCommand::class)->newInstanceWithoutConstructor();

        $defaults = iterator_to_array(new ObservabilityInstallFlags()->defaults($command), false);

        self::assertCount(1, $defaults);
        self::assertContainsOnlyInstancesOf(InstallFlagDefault::class, $defaults);
        self::assertSame('observability.evaluate_card_trace_rate_permille', $defaults[0]->name);
        self::assertSame(FeatureFlagType::Int, $defaults[0]->type);
        self::assertSame(10, $defaults[0]->value);
    }
}
