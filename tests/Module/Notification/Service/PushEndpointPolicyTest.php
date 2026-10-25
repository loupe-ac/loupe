<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Notification\Service\PushEndpointPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushEndpointPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedEndpoints(): iterable
    {
        yield 'apple' => ['https://web.push.apple.com/QGxyz'];
        yield 'apple with a deeper host' => ['https://api.sandbox.push.apple.com/3/device/abc'];
        yield 'google' => ['https://fcm.googleapis.com/fcm/send/abc:def'];
        yield 'mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/abc'];
        yield 'microsoft' => ['https://wns2-par02p.notify.windows.com/w/?token=abc'];
        yield 'upper case host' => ['https://FCM.GoogleAPIs.com/fcm/send/abc'];
        yield 'explicit default port' => ['https://web.push.apple.com:443/abc'];
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEndpoints(): iterable
    {
        yield 'private address' => ['https://10.0.0.5/admin'];
        yield 'loopback' => ['https://127.0.0.1/'];
        yield 'plain http' => ['http://web.push.apple.com/abc'];
        yield 'no scheme' => ['//web.push.apple.com/abc'];
        yield 'other scheme' => ['ftp://web.push.apple.com/abc'];
        yield 'user info' => ['https://user:pass@web.push.apple.com/abc'];
        yield 'user info that hides the host' => ['https://fcm.googleapis.com@evil.example/abc'];
        yield 'user info with a colon' => ['https://fcm.googleapis.com:pw@evil.example/abc'];
        yield 'other port' => ['https://web.push.apple.com:8443/abc'];
        yield 'lookalike without a dot boundary' => ['https://evilpush.apple.com/abc'];
        yield 'lookalike suffix' => ['https://push.apple.com.evil.example/abc'];
        yield 'bare suffix host' => ['https://push.apple.com/abc'];
        yield 'google lookalike' => ['https://evilfcm.googleapis.com/abc'];
        yield 'google sub-host' => ['https://x.fcm.googleapis.com/abc'];
        yield 'mozilla lookalike' => ['https://notpush.services.mozilla.com/abc'];
        yield 'microsoft lookalike' => ['https://evilnotify.windows.com/abc'];
        yield 'trailing dot host' => ['https://web.push.apple.com./abc'];
        yield 'backslash trick' => ['https://evil.example\\@web.push.apple.com/abc'];
        yield 'empty' => [''];
        yield 'garbage' => ['not a url'];
        yield 'no host' => ['https:///abc'];
        yield 'control character' => ["https://web.push.apple.com\n.evil.example/abc"];
    }

    #[DataProvider('acceptedEndpoints')]
    public function test_it_accepts_a_known_push_service(string $endpoint): void
    {
        self::assertTrue(new PushEndpointPolicy()->accepts($endpoint));
    }

    #[DataProvider('refusedEndpoints')]
    public function test_it_refuses_anything_else(string $endpoint): void
    {
        self::assertFalse(new PushEndpointPolicy()->accepts($endpoint));
    }
}
