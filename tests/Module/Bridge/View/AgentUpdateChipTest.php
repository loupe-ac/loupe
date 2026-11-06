<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Module\Bridge\View\AgentUpdateChip;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AgentUpdateChipTest extends TestCase
{
    public function test_a_version_outside_the_range_gives_the_command_that_installs_the_update(): void
    {
        $chip = AgentUpdateChip::for($this->bridge(CliInstallMethod::Homebrew, CliUpdateState::Current), false, 'https://example.test/install.sh');

        self::assertSame('bridge.agents.update.incompatible', $chip?->label);
        self::assertSame('brew upgrade loupe', $chip->command);
    }

    public function test_a_draining_bridge_gives_the_script_command_for_a_script_install(): void
    {
        $chip = AgentUpdateChip::for($this->bridge(null, CliUpdateState::Draining), true, 'https://example.test/install.sh');

        self::assertSame('bridge.agents.update.draining', $chip?->label);
        self::assertSame('curl -fsSL https://example.test/install.sh | sh', $chip->command);
    }

    public function test_a_current_bridge_has_no_command(): void
    {
        $chip = AgentUpdateChip::for($this->bridge(null, CliUpdateState::Current), true, 'https://example.test/install.sh');

        self::assertNull($chip?->command);
    }

    private function bridge(?CliInstallMethod $method, CliUpdateState $state): Bridge
    {
        $bridge = new Bridge(new User(fullName: 'Riley Chen', email: 'update-chip@example.com', password: 'x'), Uuid::v4(), [], '1.32.0', new \DateTimeImmutable('2026-10-16 12:00:00'));
        $bridge->installMethod = $method;
        $bridge->updateState = $state;

        return $bridge;
    }
}
