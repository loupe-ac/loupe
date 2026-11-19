#!/usr/bin/env php
<?php

declare(strict_types=1);

// Joins the JUnit logs of the PHPUnit coverage shards into one file, which
// Infection reads to find the tests of each mutated line.
// Usage: php bin/merge-junit.php <output.xml> <input.xml>...

$output = $argv[1] ?? null;
$inputs = \array_slice($argv, 2);

if (null === $output || [] === $inputs) {
    fwrite(STDERR, "usage: merge-junit.php <output.xml> <input.xml>...\n");
    exit(2);
}

$merged = new DOMDocument('1.0', 'UTF-8');
$root = $merged->appendChild($merged->createElement('testsuites'));
$tests = 0;

foreach ($inputs as $input) {
    $log = new DOMDocument();
    if (!is_file($input) || !$log->load($input) || null === $log->documentElement) {
        fwrite(STDERR, "merge-junit: cannot read $input\n");
        exit(1);
    }

    // A process that dies between opening and writing its log leaves an empty
    // file. Refuse it, because the merged log would then lack that shard's tests.
    $count = (new DOMXPath($log))->query('//testcase')->length;
    if (0 === $count) {
        fwrite(STDERR, "merge-junit: $input holds no test case\n");
        exit(1);
    }
    $tests += $count;

    $top = 'testsuites' === $log->documentElement->nodeName
        ? iterator_to_array($log->documentElement->childNodes)
        : [$log->documentElement];
    foreach ($top as $node) {
        if ($node instanceof DOMElement) {
            $root->appendChild($merged->importNode($node, true));
        }
    }
}

$merged->formatOutput = true;
$merged->save($output);
fwrite(STDOUT, "merge-junit: $tests test cases from ".\count($inputs)." logs into $output\n");
