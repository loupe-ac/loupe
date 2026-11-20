<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class CoverageShardScriptsTest extends TestCase
{
    private string $root;

    private string $dir;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
        $this->dir = sys_get_temp_dir().'/coverage-shards-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        new Filesystem()->remove(glob($this->root.'/var/phpunit-shard-9*.xml') ?: []);
    }

    public function test_the_source_shards_cover_every_file_once(): void
    {
        $seen = [];
        foreach ([1, 2, 3] as $shard) {
            $filter = $this->php(['bin/coverage-shard.php', 'src-filter', (string) $shard, '3']);
            foreach (explode(',', trim($filter)) as $file) {
                $seen[] = $file;
            }
        }

        $all = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with((string) $file->getFilename(), '.php')) {
                $all[] = 'src/'.substr((string) $file->getPathname(), \strlen($this->root.'/src/'));
            }
        }

        sort($seen);
        sort($all);
        self::assertSame($all, $seen);
    }

    public function test_the_test_shards_cover_every_test_file_once(): void
    {
        $seen = [];
        foreach ([1, 2, 3] as $shard) {
            $config = new \DOMDocument();
            $config->load(trim($this->php(['bin/coverage-shard.php', 'phpunit-config', (string) $shard, '3'])));
            foreach (new \DOMXPath($config)->query('//testsuite/file') ?: [] as $file) {
                \assert($file instanceof \DOMElement);
                $seen[] = $file->textContent;
            }
        }

        $all = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/tests', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with((string) $file->getFilename(), 'Test.php')) {
                $all[] = $file->getPathname();
            }
        }

        sort($seen);
        sort($all);
        self::assertSame($all, $seen);
    }

    public function test_a_test_shard_raises_the_worker_memory_limit_above_the_dist_value(): void
    {
        $config = new \DOMDocument();
        $config->load(trim($this->php(['bin/coverage-shard.php', 'phpunit-config', '1', '3'])));
        $limit = new \DOMXPath($config)->query('//php/ini[@name="memory_limit"]');
        self::assertNotFalse($limit);
        self::assertSame(1, $limit->length);
        $value = $limit->item(0);
        \assert($value instanceof \DOMElement);
        self::assertSame('6G', $value->getAttribute('value'));
    }

    public function test_the_dev_seed_restore_classes_run_in_four_different_shards(): void
    {
        $shardOf = [];
        foreach ([1, 2, 3, 4] as $shard) {
            $config = new \DOMDocument();
            $config->load(trim($this->php(['bin/coverage-shard.php', 'phpunit-config', (string) $shard, '4'])));
            foreach (new \DOMXPath($config)->query('//testsuite/file') ?: [] as $file) {
                \assert($file instanceof \DOMElement);
                if (str_contains($file->textContent, '/tests/DevSeed/DevSeedRestore')) {
                    $shardOf[basename($file->textContent)] = $shard;
                }
            }
        }

        self::assertCount(4, $shardOf, 'Each class of the restore test is one file, so a shard runs it in one worker.');
        self::assertCount(4, array_unique($shardOf), 'A shard takes every fourth file of a sorted list, so four neighbouring files land in four shards.');
    }

    public function test_a_shard_number_beyond_the_shard_count_is_refused(): void
    {
        $process = new Process(['php', 'bin/coverage-shard.php', 'src-filter', '4', '3'], $this->root);
        $process->run();

        self::assertSame(2, $process->getExitCode());
    }

    public function test_the_junit_logs_join_and_an_empty_log_is_refused(): void
    {
        $one = $this->dir.'/one.xml';
        $two = $this->dir.'/two.xml';
        file_put_contents($one, '<testsuites><testsuite name="a"><testcase name="x"/></testsuite></testsuites>');
        file_put_contents($two, '<testsuites><testsuite name="b"><testcase name="y"/><testcase name="z"/></testsuite></testsuites>');

        $this->php(['bin/merge-junit.php', $this->dir.'/out.xml', $one, $two]);

        $out = new \DOMDocument();
        $out->load($this->dir.'/out.xml');
        self::assertSame(3, (int) new \DOMXPath($out)->evaluate('count(//testcase)'));

        $empty = $this->dir.'/empty.xml';
        file_put_contents($empty, '<testsuites/>');
        $process = new Process(['php', 'bin/merge-junit.php', $this->dir.'/bad.xml', $one, $empty], $this->root);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/bad.xml');
    }

    public function test_the_mutation_summaries_add_up(): void
    {
        mkdir($this->dir.'/a');
        mkdir($this->dir.'/b');
        file_put_contents($this->dir.'/a/summary.log', "Total: 5\n\nKilled by Test Framework: 3\nEscaped: 2\n");
        file_put_contents($this->dir.'/b/summary.log', "Total: 4\n\nKilled by Test Framework: 4\nEscaped: 0\n");

        $this->php(['bin/merge-infection-logs.php', $this->dir.'/out', $this->dir.'/a', $this->dir.'/b']);

        self::assertSame(
            "Total: 9\n\nKilled by Test Framework: 7\nEscaped: 2\n",
            file_get_contents($this->dir.'/out/summary.log'),
        );
    }

    public function test_the_mutation_summary_names_the_share_of_skipped_mutants(): void
    {
        mkdir($this->dir.'/a');
        file_put_contents($this->dir.'/a/summary.log', "Total: 10\n\nKilled by Test Framework: 6\nSkipped: 4\n");

        $this->php(['bin/merge-infection-logs.php', $this->dir.'/out', $this->dir.'/a']);

        self::assertSame(
            "Total: 10\n\nKilled by Test Framework: 6\nSkipped: 4\nSkipped share (left out of the MSI): 40%\n",
            file_get_contents($this->dir.'/out/summary.log'),
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function php(array $arguments): string
    {
        $process = new Process(['php', ...$arguments], $this->root);
        $process->mustRun();

        return $process->getOutput();
    }
}
