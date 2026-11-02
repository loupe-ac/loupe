<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\View;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\RefusedRequest;
use App\Module\Bridge\View\AgentRefusalChip;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AgentRefusalChipTest extends TestCase
{
    public function test_a_bridge_with_no_refusal_has_no_chip(): void
    {
        self::assertNull(AgentRefusalChip::for($this->bridge('1.32.0'), []));
    }

    public function test_a_claim_with_no_account_from_an_old_cli_is_a_version_gap(): void
    {
        $bridge = $this->bridge('1.32.0');

        $chip = AgentRefusalChip::for($bridge, [$this->refusal($bridge, null)]);

        self::assertSame('bridge.agents.refused.chip', $chip?->label);
        self::assertSame('bridge.agents.refused.cause.version_gap', $chip->cause);
        self::assertSame([], $chip->parameters);
        self::assertSame('failed', $chip->tone);
    }

    public function test_a_named_account_is_the_cause_with_its_name(): void
    {
        $bridge = $this->bridge('1.32.0');

        $chip = AgentRefusalChip::for($bridge, [$this->refusal($bridge, 'work')]);

        self::assertSame('bridge.agents.refused.cause.account', $chip?->cause);
        self::assertSame(['%account%' => 'work'], $chip->parameters);
    }

    public function test_a_claim_with_no_account_from_a_current_cli_is_no_account(): void
    {
        $bridge = $this->bridge('1.33.0');

        $chip = AgentRefusalChip::for($bridge, [$this->refusal($bridge, null)]);

        self::assertSame('bridge.agents.refused.cause.no_account', $chip?->cause);
    }

    private function bridge(string $version): Bridge
    {
        return new Bridge(new User(fullName: 'Riley Chen', email: 'refusal-chip@example.com', password: 'x'), Uuid::v4(), [], $version, new \DateTimeImmutable('2026-10-16 12:00:00'));
    }

    private function refusal(Bridge $bridge, ?string $account): RefusedRequest
    {
        return new RefusedRequest($bridge->id, Uuid::v7(), 'card', Uuid::v7(), 'implement', $account);
    }
}
