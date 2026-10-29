<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Experiment;

use App\Module\Bridge\Experiment\Confidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfidenceTest extends TestCase
{
    #[DataProvider('levels')]
    public function test_the_level_widens_with_the_number_of_comparisons(int $comparisons, float $level): void
    {
        self::assertEqualsWithDelta($level, Confidence::forComparisons($comparisons)->level, 1e-12);
    }

    /** @return iterable<string, array{int, float}> */
    public static function levels(): iterable
    {
        yield 'none' => [0, 0.95];
        yield 'one' => [1, 0.95];
        yield 'two' => [2, 0.975];
        yield 'three' => [3, 1 - 0.05 / 3];
        yield 'thirty-one' => [31, 1 - 0.05 / 31];
    }

    #[DataProvider('quantiles')]
    public function test_z_is_the_two_sided_normal_quantile(int $comparisons, float $z): void
    {
        self::assertEqualsWithDelta($z, Confidence::forComparisons($comparisons)->z(), 1e-5);
    }

    /** @return iterable<string, array{int, float}> */
    public static function quantiles(): iterable
    {
        yield 'one' => [1, 1.959964];
        yield 'two' => [2, 2.241403];
        yield 'three' => [3, 2.393980];
        yield 'thirty-one' => [31, 3.153555];
    }

    public function test_the_percentiles_frame_the_level(): void
    {
        $confidence = new Confidence(0.95);

        self::assertEqualsWithDelta(0.025, $confidence->lowPercentile(), 1e-12);
        self::assertEqualsWithDelta(0.975, $confidence->highPercentile(), 1e-12);

        $wide = Confidence::forComparisons(2);
        self::assertEqualsWithDelta(0.0125, $wide->lowPercentile(), 1e-12);
        self::assertEqualsWithDelta(0.9875, $wide->highPercentile(), 1e-12);
    }
}
