<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushUnavailableReason;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class PushAvailabilityTest extends TestCase
{
    public function test_a_valid_pair_with_the_flag_on_is_available(): void
    {
        $keys = VAPID::createVapidKeys();

        $availability = $this->availability(true, $keys['publicKey'], $keys['privateKey']);

        self::assertTrue($availability->isAvailable());
        self::assertNull($availability->reason());
        $pair = $availability->keys();
        self::assertNotNull($pair);
        self::assertSame($keys['publicKey'], $pair->publicKey);
        self::assertSame($keys['privateKey'], $pair->privateKey);
    }

    public function test_the_flag_off_makes_push_unavailable(): void
    {
        $keys = VAPID::createVapidKeys();

        $availability = $this->availability(false, $keys['publicKey'], $keys['privateKey']);

        self::assertFalse($availability->isAvailable());
        self::assertSame(PushUnavailableReason::FlagOff, $availability->reason());
    }

    public function test_the_flag_is_read_on_every_call(): void
    {
        $keys = VAPID::createVapidKeys();
        $enabled = false;
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturnCallback(static function () use (&$enabled): bool {
            return $enabled;
        });
        $availability = new PushAvailability($flags, $keys['publicKey'], $keys['privateKey']);

        self::assertFalse($availability->isAvailable());
        $enabled = true;
        self::assertTrue($availability->isAvailable());
    }

    public function test_a_missing_public_key_is_named(): void
    {
        $keys = VAPID::createVapidKeys();

        self::assertSame(PushUnavailableReason::PublicKeyMissing, $this->availability(true, null, $keys['privateKey'])->reason());
        self::assertSame(PushUnavailableReason::PublicKeyMissing, $this->availability(true, '', $keys['privateKey'])->reason());
    }

    public function test_a_missing_private_key_is_named(): void
    {
        $keys = VAPID::createVapidKeys();

        self::assertSame(PushUnavailableReason::PrivateKeyMissing, $this->availability(true, $keys['publicKey'], null)->reason());
        self::assertSame(PushUnavailableReason::PrivateKeyMissing, $this->availability(true, $keys['publicKey'], '')->reason());
    }

    public function test_a_public_key_of_the_wrong_length_is_malformed(): void
    {
        $keys = VAPID::createVapidKeys();
        $availability = $this->availability(true, substr($keys['publicKey'], 0, 40), $keys['privateKey']);

        self::assertSame(PushUnavailableReason::PublicKeyMalformed, $availability->reason());
        self::assertNull($availability->keys());
    }

    public function test_a_private_key_of_the_wrong_length_is_malformed(): void
    {
        $keys = VAPID::createVapidKeys();
        $availability = $this->availability(true, $keys['publicKey'], substr($keys['privateKey'], 0, 30));

        self::assertSame(PushUnavailableReason::PrivateKeyMalformed, $availability->reason());
        self::assertNull($availability->keys());
    }

    public function test_a_key_that_is_not_base64url_is_malformed(): void
    {
        $keys = VAPID::createVapidKeys();

        self::assertSame(PushUnavailableReason::PublicKeyMalformed, $this->availability(true, '!!!not base64url!!!', $keys['privateKey'])->reason());
        self::assertSame(PushUnavailableReason::PrivateKeyMalformed, $this->availability(true, $keys['publicKey'], '***'.substr($keys['privateKey'], 3))->reason());
    }

    public function test_a_pair_of_two_different_keys_is_mismatched(): void
    {
        $first = VAPID::createVapidKeys();
        $second = VAPID::createVapidKeys();

        $availability = $this->availability(true, $first['publicKey'], $second['privateKey']);

        self::assertFalse($availability->isAvailable());
        self::assertSame(PushUnavailableReason::PairMismatched, $availability->reason());
        self::assertNull($availability->keys());
    }

    public function test_a_public_key_that_is_not_a_point_on_the_curve_is_mismatched(): void
    {
        $keys = VAPID::createVapidKeys();
        $junk = rtrim(strtr(base64_encode("\x04".str_repeat("\x01", 64)), '+/', '-_'), '=');

        self::assertSame(PushUnavailableReason::PairMismatched, $this->availability(true, $junk, $keys['privateKey'])->reason());
    }

    public function test_the_keys_do_not_depend_on_the_flag(): void
    {
        $keys = VAPID::createVapidKeys();

        self::assertNotNull($this->availability(false, $keys['publicKey'], $keys['privateKey'])->keys());
        self::assertNull($this->availability(false, 'x', 'y')->keys());
    }

    private function availability(bool $flag, ?string $publicKey, ?string $privateKey): PushAvailability
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn($flag);

        return new PushAvailability($flags, $publicKey, $privateKey);
    }
}
