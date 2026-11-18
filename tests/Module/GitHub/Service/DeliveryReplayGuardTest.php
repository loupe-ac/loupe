<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Service\DeliveryReplayGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

final class DeliveryReplayGuardTest extends TestCase
{
    private const string SECRET = 'secret';

    public function test_a_delivery_is_new_until_it_is_remembered(): void
    {
        $guard = new DeliveryReplayGuard(new ArrayAdapter());
        $delivery = $this->delivery('{"action":"created"}');

        self::assertFalse($guard->seen('app', $delivery));
        $guard->remember('app', $delivery);

        self::assertTrue($guard->seen('app', $delivery));
    }

    public function test_the_scope_and_the_body_both_make_a_new_key(): void
    {
        $guard = new DeliveryReplayGuard(new ArrayAdapter());
        $guard->remember('app', $this->delivery('{"a":1}'));

        self::assertFalse($guard->seen('hook.1', $this->delivery('{"a":1}')));
        self::assertFalse($guard->seen('app', $this->delivery('{"a":2}')));
    }

    public function test_a_delivery_is_forgotten_after_the_window(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $cache = new ArrayAdapter(clock: $clock);
        $guard = new DeliveryReplayGuard($cache);
        $delivery = $this->delivery('{"a":1}');
        $guard->remember('app', $delivery);

        $clock->sleep(2_592_000 - 60);
        self::assertTrue($guard->seen('app', $delivery));

        $clock->sleep(120);
        self::assertFalse($guard->seen('app', $delivery));
    }

    private function delivery(string $body): GitHubDelivery
    {
        return GitHubDelivery::fromRequest(Request::create('/', Request::METHOD_POST, server: [
            'HTTP_X_GITHUB_EVENT' => 'installation',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], content: $body), self::SECRET);
    }
}
