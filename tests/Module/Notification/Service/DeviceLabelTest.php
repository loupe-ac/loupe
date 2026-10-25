<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Service\DeviceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceLabelTest extends TestCase
{
    #[DataProvider('agents')]
    public function test_it_names_the_browser_and_the_system(?string $userAgent, string $expected): void
    {
        self::assertSame($expected, DeviceLabel::fromUserAgent($userAgent));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function agents(): iterable
    {
        yield 'chrome on mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'Chrome on macOS'];
        yield 'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iOS'];
        yield 'safari on ipad' => ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iPadOS'];
        yield 'chrome on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.0.0 Mobile/15E148 Safari/604.1', 'Chrome on iOS'];
        yield 'firefox on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox on Windows'];
        yield 'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge on Windows'];
        yield 'chrome on android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36', 'Chrome on Android'];
        yield 'firefox on linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0', 'Firefox on Linux'];
        yield 'safari on mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15', 'Safari on macOS'];
        yield 'unknown agent' => ['curl/8.0', 'Browser'];
        yield 'missing agent' => [null, 'Browser'];
        yield 'empty agent' => ['', 'Browser'];
    }

    public function test_it_never_exceeds_the_label_limit(): void
    {
        self::assertLessThanOrEqual(PushDevice::LABEL_MAX_LENGTH, mb_strlen((string) DeviceLabel::normalize(str_repeat('é', 500))));
    }

    public function test_it_trims_and_collapses_blank_to_null(): void
    {
        self::assertSame('Work laptop', DeviceLabel::normalize("  Work laptop \n"));
        self::assertNull(DeviceLabel::normalize('   '));
        self::assertNull(DeviceLabel::normalize(null));
    }
}
