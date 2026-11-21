#!/usr/bin/env php
<?php

declare(strict_types=1);

// Writes var/coverage/baseline.cov: a coverage file that lists every PHP file of
// src/ with no line run. The e2e collector records only the files a request ran,
// so without this file the merged report would leave out every file that no
// browser test reaches, and its percentages would read too high.
// Needs xdebug in coverage mode. Usage: php bin/coverage-baseline.php [output-dir]

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

$outputDir = $argv[1] ?? $root.'/var/coverage';

$filter = new Filter();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
    if ($file->isFile() && 'php' === $file->getExtension()) {
        $filter->includeFile($file->getRealPath());
    }
}

$coverage = new CodeCoverage(new Selector()->forLineCoverage($filter), $filter);
$coverage->cacheStaticAnalysis($root.'/.phpunit.cache/code-coverage');
$coverage->includeUncoveredFiles();
$coverage->start('baseline');
$coverage->stop();

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "coverage-baseline: cannot create $outputDir\n");
    exit(1);
}

$target = $outputDir.'/baseline.cov';
new Serializer()->serialize($target, $coverage);

$files = \count($coverage->getData()->coveredFiles());
fwrite(STDOUT, "coverage-baseline: $target lists $files files\n");
