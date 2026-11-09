<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\PublicExperimentResult;

/**
 * The version of a preview image: a fingerprint of the kept result. The compute time and the two windows follow the
 * clock and do not change the image, so they stay out. A line graph whose last bucket is open at the time of the read
 * draws a dashed segment, so that fact is part of the version and the image redraws when the bucket closes.
 * A graph that cannot be drawn has the version `broken`.
 */
final class PublicPreviewVersion
{
    public const string BROKEN = 'broken';

    private const int LENGTH = 16;

    /** @param bool $legend whether the graph shows its legend, which changes the image and is not part of the result */
    public static function of(KeptGraphResult|PublicExperimentResult|null $result, \DateTimeImmutable $at, bool $legend = false): string
    {
        if (null === $result) {
            return self::BROKEN;
        }
        $epoch = new \DateTimeImmutable('@0');
        $window = new MetricWindow(null, $epoch);
        $stripped = $result instanceof KeptGraphResult
            ? new KeptGraphResult($epoch, $result->query, $result->series, $result->groupLabels, $window, null === $result->previous ? null : $window)
            : new PublicExperimentResult($epoch, $result->experiment, $result->headline, $result->variants, $result->metrics);

        $input = $result instanceof KeptGraphResult && $result->drawsOpenLine($at) ? [$stripped, $legend, true] : [$stripped, $legend];

        return substr(hash('sha256', serialize($input)), 0, self::LENGTH);
    }
}
