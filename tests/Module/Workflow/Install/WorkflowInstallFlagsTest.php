<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class WorkflowInstallFlagsTest extends TestCase
{
    public function test_a_fresh_install_seeds_the_change_driven_sweep_on_and_the_full_sweep_every_24_hours(): void
    {
        $defaults = [];
        foreach (new WorkflowInstallFlags()->defaults(new SeedInstallFlagsCommand(10, true, false, 0, null, false, false)) as $default) {
            $defaults[$default->name] = $default;
        }

        self::assertSame(FeatureFlagType::Bool, $defaults[WorkflowInstallFlags::FLAG_CHANGE_DRIVEN_SWEEP]->type);
        self::assertTrue($defaults[WorkflowInstallFlags::FLAG_CHANGE_DRIVEN_SWEEP]->value);
        self::assertSame(FeatureFlagType::Int, $defaults[WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS]->type);
        self::assertSame(24, $defaults[WorkflowInstallFlags::FLAG_FULL_SWEEP_INTERVAL_HOURS]->value);
    }
}
