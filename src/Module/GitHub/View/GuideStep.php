<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** One numbered step of the "Fix it on GitHub" box. */
final readonly class GuideStep
{
    public const string IMPORT = 'import';
    public const string VERIFY_SOURCE = 'verify_source';
    public const string REVIEW = 'review';
    public const string UNPINNED = 'unpinned';
    public const string CI_CHECKS = 'ci_checks';
    public const string PROTECTION = 'protection';
    public const string MERGE_METHOD = 'merge_method';

    /**
     * @param list<GuideText> $texts the sentences of the step, in order
     * @param list<GuideLink> $links
     */
    public function __construct(
        public string $kind,
        public array $texts,
        public array $links = [],
    ) {
    }
}
