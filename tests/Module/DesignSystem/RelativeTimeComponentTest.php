<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Twig\RelativeTimeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class RelativeTimeComponentTest extends KernelTestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render($context);
    }

    public function test_relative_mode_draws_the_age_with_the_full_timestamp_as_title(): void
    {
        $when = new \DateTimeImmutable('2026-10-05 12:00:00 UTC');
        $now = new \DateTimeImmutable('2026-10-05 14:30:00 UTC');

        $html = $this->render('<twig:Ds:RelativeTime :datetime="when" :now="now" />', ['when' => $when, 'now' => $now]);

        self::assertStringContainsString('<time class="lp-relative-time"', $html);
        self::assertStringContainsString('datetime="2026-10-05T12:00:00+00:00"', $html);
        self::assertStringContainsString('title="2026-10-05 12:00:00 UTC"', $html);
        self::assertStringContainsString('>2h ago</time>', $html);
    }

    public function test_date_mode_draws_the_day_month_and_year(): void
    {
        $when = new \DateTimeImmutable('2026-10-05 14:30:00 UTC');

        $html = $this->render('<twig:Ds:RelativeTime :datetime="when" mode="date" />', ['when' => $when]);

        self::assertStringContainsString('>5 Oct 2026</time>', $html);
    }

    public function test_datetime_mode_adds_the_hour_and_minute(): void
    {
        $when = new \DateTimeImmutable('2026-10-05 14:30:00 UTC');

        $html = $this->render('<twig:Ds:RelativeTime :datetime="when" mode="datetime" />', ['when' => $when]);

        self::assertStringContainsString('>5 Oct 2026, 14:30</time>', $html);
    }

    public function test_a_caller_class_is_merged_with_the_component_class(): void
    {
        $when = new \DateTimeImmutable('2026-10-05 14:30:00 UTC');

        $html = $this->render('<twig:Ds:RelativeTime :datetime="when" mode="date" class="x" role="cell" />', ['when' => $when]);

        self::assertMatchesRegularExpression('/class="(lp-relative-time x|x lp-relative-time)"/', $html);
        self::assertStringContainsString('role="cell"', $html);
    }

    public function test_a_caller_title_replaces_the_default_title(): void
    {
        $when = new \DateTimeImmutable('2026-10-05 14:30:00 UTC');

        $html = $this->render('<twig:Ds:RelativeTime :datetime="when" mode="date" title="Custom" />', ['when' => $when]);

        self::assertStringContainsString('title="Custom"', $html);
        self::assertStringNotContainsString('2026-10-05 14:30:00 UTC', $html);
    }

    /** @return iterable<string, array{int, string}> */
    public static function buckets(): iterable
    {
        yield 'now' => [0, 'just now'];
        yield 'future clamps to now' => [-500, 'just now'];
        yield 'last second of the first minute' => [59, 'just now'];
        yield 'one minute' => [60, '1m ago'];
        yield 'five minutes' => [300, '5m ago'];
        yield 'one hour' => [3600, '1h ago'];
        yield 'two hours' => [7200, '2h ago'];
        yield 'one day' => [86400, '1d ago'];
        yield 'three days' => [3 * 86400, '3d ago'];
        yield 'one week' => [604800, 'last week'];
        yield 'just under two weeks' => [1209599, 'last week'];
        yield 'two weeks' => [1209600, '2w ago'];
        yield 'three weeks' => [3 * 604800, '3w ago'];
        yield 'one month' => [2592000, 'last month'];
        yield 'just under two months' => [5259599, 'last month'];
        yield 'two months' => [5259600, '2mo ago'];
        yield 'four months' => [4 * 2592000, '4mo ago'];
        yield 'one year' => [31557600, '1y ago'];
        yield 'three years' => [3 * 31557600, '3y ago'];
    }

    #[DataProvider('buckets')]
    public function test_the_filter_buckets_an_age(int $secondsAgo, string $expected): void
    {
        self::bootKernel();
        $extension = static::getContainer()->get(RelativeTimeExtension::class);
        $now = new \DateTimeImmutable('2026-10-05 12:00:00 UTC');

        self::assertSame($expected, $extension->relativeTime($now->modify(sprintf('-%d seconds', $secondsAgo)), $now));
    }

    public function test_the_filter_counts_from_the_current_time_without_a_now(): void
    {
        self::bootKernel();
        $extension = static::getContainer()->get(RelativeTimeExtension::class);

        self::assertSame('2h ago', $extension->relativeTime(new \DateTimeImmutable('-2 hours -1 minute')));
    }

    public function test_the_filter_takes_a_mutable_date(): void
    {
        self::bootKernel();
        $extension = static::getContainer()->get(RelativeTimeExtension::class);
        $now = new \DateTime('2026-10-05 12:00:00 UTC');

        self::assertSame('5m ago', $extension->relativeTime(new \DateTime('2026-10-05 11:55:00 UTC'), $now));
    }
}
