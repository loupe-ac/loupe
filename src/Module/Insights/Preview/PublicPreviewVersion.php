<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\PublicExperimentResult;

/**
 * The version of a preview image: a fingerprint of the kept result. The compute time and the two windows follow the
 * clock and do not change the image, so they stay out. A graph that cannot be drawn has the version `broken`.
 */
final class PublicPreviewVersion
{
    public const string BROKEN = 'broken';

    private const int LENGTH = 16;

    /** @param bool $legend whether the graph shows its legend, which changes the image and is not part of the result */
    public static function of(KeptGraphResult|PublicExperimentResult|null $result, bool $legend = false): string
    {
        if (null === $result) {
            return self::BROKEN;
        }
        $epoch = new \DateTimeImmutable('@0');
        $window = new MetricWindow(null, $epoch);
        $stripped = $result instanceof KeptGraphResult
            ? new KeptGraphResult($epoch, $result->query, $result->series, $result->groupLabels, $window, null === $result->previous ? null : $window)
            : new PublicExperimentResult($epoch, $result->experiment, $result->headline, $result->variants, $result->metrics);

        return substr(hash('sha256', serialize([$stripped, $legend])), 0, self::LENGTH);
    }
}
