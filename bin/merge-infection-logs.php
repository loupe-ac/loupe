#!/usr/bin/env php
<?php

declare(strict_types=1);

// Joins the logs of the mutation shards. summary.log holds one count on each
// line, so the counts add up. infection.log holds the lists of escaped, timed
// out and uncovered mutants, so the shard logs follow one another.
// Usage: php bin/merge-infection-logs.php <output-dir> <shard-dir>...

$outputDir = $argv[1] ?? null;
$shardDirs = \array_slice($argv, 2);

if (null === $outputDir || [] === $shardDirs) {
    fwrite(STDERR, "usage: merge-infection-logs.php <output-dir> <shard-dir>...\n");
    exit(2);
}

/** @var array<string, int> $totals */
$totals = [];
$detail = '';

foreach ($shardDirs as $dir) {
    $summary = $dir.'/summary.log';
    if (!is_file($summary)) {
        fwrite(STDERR, "merge-infection-logs: $summary is missing\n");
        exit(1);
    }

    foreach (file($summary, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (1 !== preg_match('/^([A-Za-z ]+): (\d+)$/', $line, $match)) {
            fwrite(STDERR, "merge-infection-logs: unexpected line in $summary: $line\n");
            exit(1);
        }
        $totals[$match[1]] = ($totals[$match[1]] ?? 0) + (int) $match[2];
    }

    if (is_file($dir.'/infection.log')) {
        $detail .= '# '.basename($dir)."\n".file_get_contents($dir.'/infection.log')."\n";
    }
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "merge-infection-logs: cannot create $outputDir\n");
    exit(1);
}

$lines = [];
foreach ($totals as $label => $count) {
    $lines[] = "$label: $count";
    if ('Total' === $label) {
        $lines[] = '';
    }
}
file_put_contents($outputDir.'/summary.log', implode("\n", $lines)."\n");
file_put_contents($outputDir.'/infection.log', $detail);
fwrite(STDOUT, 'merge-infection-logs: '.\count($shardDirs).' shards, total '.($totals['Total'] ?? 0)." mutants\n");
