<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

/** The part of the Analytics pages that an analytics.changed signal names. The values are the wire names. */
final class AnalyticsArea
{
    public const string EXPERIMENTS = 'experiments';
    public const string REPORTS = 'reports';
    public const string BUCKET_RULES = 'bucket_rules';

    /** The id of the reports signal that names the analytics settings and no analysis. */
    public const string SETTINGS_ID = 'settings';

    private function __construct()
    {
    }
}
