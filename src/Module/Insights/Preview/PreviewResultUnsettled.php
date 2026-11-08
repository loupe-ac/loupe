<?php

declare(strict_types=1);

namespace App\Module\Insights\Preview;

/** The result changed during each of the draws, so no draw shows a confirmed result. */
final class PreviewResultUnsettled extends \RuntimeException
{
}
