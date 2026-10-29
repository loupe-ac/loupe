<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

final readonly class Confidence
{
    public function __construct(
        public float $level,
    ) {
    }

    /**
     * The level is 0.95 for zero or one comparison. For more it is 1 - 0.05 / comparisons.
     */
    public static function forComparisons(int $comparisons): self
    {
        return new self($comparisons <= 1 ? 0.95 : 1 - 0.05 / $comparisons);
    }

    /**
     * The two-sided normal quantile of the level, from the Acklam approximation.
     */
    public function z(): float
    {
        return self::inverseNormal($this->highPercentile());
    }

    public function lowPercentile(): float
    {
        return (1 - $this->level) / 2;
    }

    public function highPercentile(): float
    {
        return 1 - $this->lowPercentile();
    }

    private static function inverseNormal(float $p): float
    {
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        $low = 0.02425;

        if ($p < $low) {
            $q = sqrt(-2 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        if ($p > 1 - $low) {
            $q = sqrt(-2 * log(1 - $p));

            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        $q = $p - 0.5;
        $r = $q * $q;

        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }
}
