<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\WorkRequestLease;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class WorkRequestLeaseTest extends TestCase
{
    public function test_the_lease_ends_after_the_given_seconds(): void
    {
        $until = new WorkRequestLease(1800)->until(new \DateTimeImmutable('2026-10-16T12:00:00+00:00'));

        self::assertSame('2026-10-16T12:30:00+00:00', $until->format(\DateTimeInterface::ATOM));
    }

    public function test_a_bridge_keeps_its_work_for_thirty_minutes_by_default(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../../../../config/services.yaml');

        self::assertSame(1800, $services['parameters']['app.bridge.work_request_lease_seconds']);
    }
}
