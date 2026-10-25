<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Notification\Install\NotificationInstallFlags;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class NotificationInstallFlagsTest extends TestCase
{
    public function test_a_fresh_install_gets_web_push_off(): void
    {
        $command = new \ReflectionClass(SeedInstallFlagsCommand::class)->newInstanceWithoutConstructor();

        $defaults = iterator_to_array(new NotificationInstallFlags()->defaults($command), false);

        self::assertCount(1, $defaults);
        self::assertContainsOnlyInstancesOf(InstallFlagDefault::class, $defaults);
        self::assertSame('notifications.web_push.enabled', $defaults[0]->name);
        self::assertSame(FeatureFlagType::Bool, $defaults[0]->type);
        self::assertFalse($defaults[0]->value);
    }
}
