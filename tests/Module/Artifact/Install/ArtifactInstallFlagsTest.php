<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Artifact\Install\ArtifactInstallFlags;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class ArtifactInstallFlagsTest extends TestCase
{
    public function test_the_flags_are_seeded_with_their_defaults(): void
    {
        $defaults = [];
        foreach (new ArtifactInstallFlags()->defaults(new SeedInstallFlagsCommand(50, true, false, 14, null, false, false)) as $default) {
            self::assertInstanceOf(InstallFlagDefault::class, $default);
            $defaults[$default->name] = [$default->type, $default->value];
        }

        self::assertSame([
            'artifacts.enabled' => [FeatureFlagType::Bool, false],
            'artifacts.max_version_bytes' => [FeatureFlagType::Int, 5242880],
            'artifacts.max_files' => [FeatureFlagType::Int, 50],
        ], $defaults);
    }
}
