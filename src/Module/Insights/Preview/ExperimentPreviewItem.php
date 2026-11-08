<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

use App\Module\Insights\View\ExperimentResultStrip;
use App\Module\Insights\View\ExperimentResultTone;

/** One row or one card of the experiment image. A card has only a name and a value. */
final readonly class ExperimentPreviewItem
{
    public function __construct(
        public string $name,
        /** The goal value, or a dash when the variant has no data. */
        public string $value,
        /** The signed change, such as "−31%". Null when the comparison has no change, and always null on a card. */
        public ?string $change = null,
        public ExperimentResultTone $tone = ExperimentResultTone::None,
        /** The result chip text of the share page. Null on a card. */
        public ?string $chip = null,
        /** ok, pending, failed or neutral. Null on a card. */
        public ?string $chipTone = null,
        /** Null when the comparison has no change, and on a card. */
        public ?ExperimentResultStrip $strip = null,
    ) {
    }
}
