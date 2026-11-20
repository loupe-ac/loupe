#!/usr/bin/env php
<?php

declare(strict_types=1);

// Splits the weekly PHPUnit coverage run and the mutation run into shards. A shard
// takes every Nth file of a sorted list, so a new test file or source file lands
// in a shard by itself and no file is left out of the run.
//
// Usage:
//   php bin/coverage-shard.php phpunit-config <shard> <of>  writes var/phpunit-shard-<shard>.xml
//   php bin/coverage-shard.php src-filter <shard> <of>      prints the Infection --filter value

$root = dirname(__DIR__);
$argv = $_SERVER['argv'] ?? [];

/**
 * @return list<string> the files below $directory that match $suffix, sorted, relative to the project root
 */
function files(string $root, string $directory, string $suffix): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
            $found[] = $directory.'/'.substr($file->getPathname(), \strlen($root.'/'.$directory) + 1);
        }
    }
    sort($found);

    return $found;
}

/**
 * @param list<string> $files
 *
 * @return list<string>
 */
function shardOf(array $files, int $shard, int $of): array
{
    $picked = [];
    foreach ($files as $index => $file) {
        if ($index % $of === $shard - 1) {
            $picked[] = $file;
        }
    }

    return $picked;
}

$command = $argv[1] ?? '';
$shard = (int) ($argv[2] ?? 0);
$of = (int) ($argv[3] ?? 0);

if ($shard < 1 || $of < 1 || $shard > $of) {
    fwrite(STDERR, "usage: coverage-shard.php phpunit-config|src-filter <shard> <of>\n");
    exit(2);
}

if ('src-filter' === $command) {
    $filter = implode(',', shardOf(files($root, 'src', '.php'), $shard, $of));
    // A single argument holds 128 KiB at most on Linux. Fail loudly before the
    // shell does, because a cut filter would mutate fewer files and still look green.
    if (\strlen($filter) > 100_000) {
        fwrite(STDERR, "coverage-shard: the filter of shard $shard is ".\strlen($filter)." bytes. Raise the shard count.\n");
        exit(1);
    }
    echo $filter, "\n";
    exit(0);
}

if ('phpunit-config' === $command) {
    $dist = new DOMDocument();
    $dist->load($root.'/phpunit.dist.xml');
    $phpunit = $dist->documentElement;
    \assert($phpunit instanceof DOMElement);

    $phpunit->removeAttribute('xsi:noNamespaceSchemaLocation');
    $phpunit->setAttribute('bootstrap', $root.'/'.$phpunit->getAttribute('bootstrap'));
    $phpunit->setAttribute('cacheDirectory', $root.'/'.$phpunit->getAttribute('cacheDirectory'));

    $xpath = new DOMXPath($dist);
    // A worker writes its coverage after its last test file, and 512M is too
    // little for that: the worker dies with no message. The paratest parent reads
    // the same limit while it joins the worker coverage, and 2G is too little there.
    foreach ($xpath->query('//php/ini[@name="memory_limit"]') ?: [] as $ini) {
        if ($ini instanceof DOMElement) {
            $ini->setAttribute('value', '6G');
        }
    }
    foreach ($xpath->query('//source/include/directory') ?: [] as $directory) {
        if ($directory instanceof DOMElement) {
            $directory->textContent = $root.'/'.trim($directory->textContent);
        }
    }

    $suite = $dist->getElementsByTagName('testsuite')->item(0);
    \assert($suite instanceof DOMElement);
    while (null !== $suite->firstChild) {
        $suite->removeChild($suite->firstChild);
    }
    $tests = shardOf(files($root, 'tests', 'Test.php'), $shard, $of);
    foreach ($tests as $test) {
        $suite->appendChild($dist->createElement('file', $root.'/'.$test));
    }

    $target = $root."/var/phpunit-shard-$shard.xml";
    if (!is_dir(\dirname($target))) {
        mkdir(\dirname($target), 0777, true);
    }
    $dist->formatOutput = true;
    $dist->save($target);
    fwrite(STDOUT, $target."\n");
    fwrite(STDERR, \count($tests)." test files in shard $shard of $of\n");
    exit(0);
}

fwrite(STDERR, "coverage-shard: unknown command '$command'\n");
exit(2);
