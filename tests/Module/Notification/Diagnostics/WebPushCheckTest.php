<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Diagnostics;

use App\Module\Notification\Diagnostics\WebPushCheck;
use App\Module\Notification\Service\PushAvailability;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class WebPushCheckTest extends TestCase
{
    public function test_a_valid_pair_with_the_flag_on_is_ok(): void
    {
        $keys = VAPID::createVapidKeys();

        $diagnostic = $this->diagnose(true, $keys['publicKey'], $keys['privateKey']);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('notifications.system_status.web_push.available', $diagnostic->detail);
    }

    public function test_the_flag_off_is_ok_and_says_push_is_off(): void
    {
        $diagnostic = $this->diagnose(false, null, null);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('notifications.system_status.web_push.flag_off', $diagnostic->detail);
    }

    public function test_a_missing_key_fails_and_names_it(): void
    {
        $keys = VAPID::createVapidKeys();

        $public = $this->diagnose(true, null, $keys['privateKey']);
        $private = $this->diagnose(true, $keys['publicKey'], '');

        self::assertSame(DiagnosticState::Failed, $public->state);
        self::assertSame('notifications.system_status.web_push.public_key_missing', $public->detail);
        self::assertSame(DiagnosticState::Failed, $private->state);
        self::assertSame('notifications.system_status.web_push.private_key_missing', $private->detail);
    }

    public function test_a_malformed_key_fails_and_names_it(): void
    {
        $keys = VAPID::createVapidKeys();

        $public = $this->diagnose(true, 'short', $keys['privateKey']);
        $private = $this->diagnose(true, $keys['publicKey'], 'short');

        self::assertSame(DiagnosticState::Failed, $public->state);
        self::assertSame('notifications.system_status.web_push.public_key_malformed', $public->detail);
        self::assertSame(DiagnosticState::Failed, $private->state);
        self::assertSame('notifications.system_status.web_push.private_key_malformed', $private->detail);
    }

    public function test_a_mismatched_pair_fails(): void
    {
        $first = VAPID::createVapidKeys();
        $second = VAPID::createVapidKeys();

        $diagnostic = $this->diagnose(true, $first['publicKey'], $second['privateKey']);

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('notifications.system_status.web_push.pair_mismatched', $diagnostic->detail);
    }

    public function test_it_never_carries_a_key_value(): void
    {
        $keys = VAPID::createVapidKeys();
        $diagnostics = [
            $this->diagnose(true, $keys['publicKey'], $keys['privateKey']),
            $this->diagnose(true, $keys['publicKey'], 'short'),
            $this->diagnose(true, 'short', $keys['privateKey']),
        ];

        foreach ($diagnostics as $diagnostic) {
            $text = json_encode($diagnostic, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($keys['privateKey'], $text);
            self::assertStringNotContainsString($keys['publicKey'], $text);
            self::assertSame([], $diagnostic->detailParameters);
        }
    }

    private function diagnose(bool $flag, ?string $publicKey, ?string $privateKey): Diagnostic
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn($flag);

        return new WebPushCheck(new PushAvailability($flags, $publicKey, $privateKey))();
    }
}
