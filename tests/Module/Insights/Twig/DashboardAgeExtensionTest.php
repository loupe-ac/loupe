<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Twig;

use App\Module\Insights\Twig\DashboardAgeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DashboardAgeExtensionTest extends KernelTestCase
{
    #[DataProvider('ages')]
    public function test_the_age_counts_in_the_largest_whole_unit(int $seconds, string $expected): void
    {
        self::assertSame($expected, $this->extension()->text($seconds));
    }

    /** @return iterable<string, array{int, string}> */
    public static function ages(): iterable
    {
        yield 'under a minute' => [30, 'Updated 0 min ago'];
        yield 'a clock that runs behind' => [-5, 'Updated 0 min ago'];
        yield 'minutes' => [59 * 60 + 59, 'Updated 59 min ago'];
        yield 'one hour' => [3600, 'Updated 1 hour ago'];
        yield 'hours' => [23 * 3600 + 59 * 60, 'Updated 23 hours ago'];
        yield 'one day' => [86400, 'Updated 1 day ago'];
        yield 'days' => [9 * 86400 + 5000, 'Updated 9 days ago'];
    }

    public function test_the_controller_gets_every_sentence_with_the_count_left_open(): void
    {
        $texts = $this->extension()->texts();

        self::assertSame(['minutes', 'hour', 'hours', 'day', 'days'], array_keys($texts));
        self::assertSame('Updated %count% days ago', $texts['days']);
    }

    private function extension(): DashboardAgeExtension
    {
        self::bootKernel();
        $extension = self::getContainer()->get(DashboardAgeExtension::class);
        self::assertInstanceOf(DashboardAgeExtension::class, $extension);

        return $extension;
    }
}
