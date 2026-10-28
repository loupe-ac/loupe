<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class RotateSecretsTest extends TestCase
{
    private const string OLD_KEY = 'T0xELEtFWS1PTEQtS0VZLU9MRC1LRVktT0xELUtFWS1PTA==';

    private const string REQUIRED = "region = \"tor\"\nmailer_dsn = \"null://null\"\nmailer_from_address = \"a@example.com\"\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/rotate-secrets-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/tf', recursive: true);
        copy(\dirname(__DIR__, 2).'/terraform/variables.tf', $this->dir.'/tf/variables.tf');
        file_put_contents($this->dir.'/secrets.vars', self::REQUIRED."app_secret = \"keep-me\"\napp_encryption_key = \"".self::OLD_KEY."\"\n");
        // The stub apply records what each deploy would carry, as yes/no, never as a value.
        file_put_contents($this->dir.'/apply.sh', <<<'SH'
            #!/usr/bin/env bash
            f="$(dirname "$0")/secrets.vars"
            cur=$(awk -F'"' '/^app_encryption_key[ \t]*=/ {print $2}' "$f")
            prev=$(awk -F'"' '/^app_encryption_key_previous[ \t]*=/ {print $2}' "$f")
            old=T0xELEtFWS1PTEQtS0VZLU9MRC1LRVktT0xELUtFWS1PTA==
            label() { if [ -z "$1" ]; then echo none; elif [ "$1" = "$old" ]; then echo old; else echo new; fi; }
            log="$(dirname "$0")/applies.log"
            echo "current=$(label "$cur") previous=$(label "$prev")" >> "$log"
            fail="$(dirname "$0")/fail-at"
            [ ! -f "$fail" ] || [ "$(wc -l < "$log")" -ne "$(cat "$fail")" ] || { rm "$fail"; exit 1; }
            SH);
        chmod($this->dir.'/apply.sh', 0o755);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function test_it_rotates_the_key_in_three_deploys_and_never_prints_a_key(): void
    {
        $process = $this->rotate(['app_encryption_key'], "yes\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old', 'current=new previous=none'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
        $tfvars = (string) file_get_contents($this->dir.'/secrets.vars');
        self::assertStringContainsString('app_secret = "keep-me"', $tfvars);
        self::assertStringNotContainsString(self::OLD_KEY, $tfvars);
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertStringNotContainsString(self::OLD_KEY, $output);
        self::assertStringContainsString('doctrine-extra:reencrypt', $output);
        self::assertStringContainsString('Revoke by hand', $output);
        self::assertSame(1, preg_match('/^app_encryption_key = "([^"]+)"$/m', $tfvars, $match));
        self::assertNotSame('', $match[1] ?? '');
        self::assertStringNotContainsString($match[1] ?? '', $output);
    }

    public function test_it_stops_before_removing_the_old_key_when_the_operator_does_not_confirm(): void
    {
        $process = $this->rotate([], "no\n");

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
        self::assertStringContainsString('app_encryption_key_previous = "'.self::OLD_KEY.'"', (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertStringNotContainsString(self::OLD_KEY, $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_rerun_after_the_operator_stops_resumes_and_keeps_the_old_key(): void
    {
        $this->rotate([], "no\n");
        $process = $this->rotate([], "yes\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old', 'current=new previous=old', 'current=new previous=none'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
        self::assertStringContainsString('resuming a rotation at deploy 2', $process->getOutput());
        self::assertFileDoesNotExist($this->dir.'/secrets.vars.rotating');
    }

    /**
     * @return iterable<string, array{int, list<string>}>
     */
    public static function failedApplies(): iterable
    {
        yield 'deploy 1' => [1, ['current=old previous=new', 'current=old previous=new', 'current=new previous=old', 'current=new previous=none']];
        yield 'deploy 2' => [2, ['current=old previous=new', 'current=new previous=old', 'current=new previous=old', 'current=new previous=none']];
        yield 'deploy 3' => [3, ['current=old previous=new', 'current=new previous=old', 'current=new previous=none', 'current=new previous=none']];
    }

    /**
     * @param list<string> $applies
     */
    #[DataProvider('failedApplies')]
    public function test_a_rerun_after_a_failed_apply_resumes_with_the_same_two_keys(int $failAt, array $applies): void
    {
        file_put_contents($this->dir.'/fail-at', (string) $failAt);
        $first = $this->rotate([], "yes\n");
        self::assertNotSame(0, $first->getExitCode());

        $process = $this->rotate([], "yes\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($applies, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES));
        self::assertStringContainsString("resuming a rotation at deploy {$failAt}", $process->getOutput());
    }

    public function test_it_reads_a_quoted_key_that_has_an_inline_comment(): void
    {
        file_put_contents($this->dir.'/secrets.vars', self::REQUIRED."app_secret = \"keep-me\"\napp_encryption_key = \"".self::OLD_KEY."\" # the live key\n");

        $process = $this->rotate([], "no\n");

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString("\napp_encryption_key_previous = \"".self::OLD_KEY."\"\n", (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_it_replaces_the_file_by_rename_and_keeps_its_mode(): void
    {
        $file = $this->dir.'/secrets.vars';
        chmod($file, 0o600);
        $inode = fileinode($file);

        $process = $this->rotate([], "yes\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        clearstatcache();
        self::assertNotSame($inode, fileinode($file));
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame([$file], glob($file.'*'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
    }

    public function test_it_refuses_to_start_while_a_previous_key_is_set_with_no_saved_rotation(): void
    {
        file_put_contents($this->dir.'/secrets.vars', "app_encryption_key_previous = \"other\"\n", \FILE_APPEND);

        $process = $this->rotate([], "yes\n");

        self::assertSame(1, $process->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertStringContainsString('no rotation is saved', $process->getErrorOutput());
    }

    public function test_it_refuses_an_unknown_secret_before_any_apply(): void
    {
        $process = $this->rotate(['no_such_secret'], '');

        self::assertSame(1, $process->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertStringContainsString("unknown secret 'no_such_secret'", $process->getErrorOutput());
    }

    public function test_a_name_of_a_pair_runs_the_step_of_its_group_once(): void
    {
        $process = $this->rotate(['app_encryption_key_previous', 'app_encryption_key'], "yes\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertCount(3, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function test_an_unknown_secret_lists_the_known_names(): void
    {
        $process = $this->rotate(['no_such_secret'], '');

        self::assertStringContainsString('Known: app_encryption_key app_encryption_key_previous', $process->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardValues(): iterable
    {
        yield 'double quote' => ['a"b'];
        yield 'backslash' => ['a\\b\\\\c\\n'];
        yield 'interpolation' => ['a${var.x}b'];
        yield 'directive' => ['a%{if true}b%{endif}'];
        yield 'escaped interpolation' => ['a$${b $$${c %%{d'];
        yield 'heredoc terminator line' => ["line one\nEOT\nline two"];
        yield 'trailing line break' => ["value\n"];
        yield 'tab and carriage return' => ["a\tb\r\nc"];
        yield 'unicode' => ['clé ünï 日本'];
        yield 'pem key' => ["-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\nabc/def+ghi==\n-----END PRIVATE KEY-----\n"];
    }

    #[DataProvider('awkwardValues')]
    public function test_the_writer_stores_a_value_that_terraform_reads_back_exactly(string $value): void
    {
        $process = $this->lib('tfvars_write app_secret "$V"; tfvars_b64 app_secret', ['V' => $value]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(base64_encode($value), trim($process->getOutput()));
        self::assertSame(base64_encode($value), $this->readWithTerraform('app_secret'));
        self::assertStringNotContainsString($value, $process->getErrorOutput());
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
    }

    public function test_the_writer_appends_a_missing_name_and_keeps_the_other_lines(): void
    {
        $process = $this->lib('tfvars_write install_token "tok"; tfvars_write app_secret "new"', []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $file = (string) file_get_contents($this->dir.'/secrets.vars');
        self::assertStringContainsString("\ninstall_token = \"tok\"\n", $file);
        self::assertStringContainsString('app_encryption_key = "'.self::OLD_KEY.'"', $file);
        self::assertSame(1, substr_count($file, 'app_secret'));
    }

    public function test_the_writer_replaces_a_heredoc_value_and_leaves_a_look_alike_line_in_another_heredoc(): void
    {
        file_put_contents($this->dir.'/secrets.vars', self::REQUIRED
            ."app_secret = <<-EOT\n  old-line\n  EOT\n"
            ."install_token = <<EOT\napp_secret = \"decoy\"\nEOT\n"
            .'app_encryption_key = "'.self::OLD_KEY."\"\n");

        $process = $this->lib('tfvars_write app_secret "fresh"', []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $file = (string) file_get_contents($this->dir.'/secrets.vars');
        self::assertStringNotContainsString('old-line', $file);
        self::assertStringContainsString("install_token = <<EOT\napp_secret = \"decoy\"\nEOT\n", $file);
        self::assertSame(base64_encode('fresh'), $this->readWithTerraform('app_secret'));
        self::assertSame(base64_encode("app_secret = \"decoy\"\n"), $this->readWithTerraform('install_token'));
    }

    public function test_the_reader_handles_a_heredoc_and_an_inline_comment(): void
    {
        file_put_contents($this->dir.'/secrets.vars', self::REQUIRED
            ."app_secret = <<EOT\nline1\nline2\nEOT\n"
            .'app_encryption_key = "abc" # the live key'."\n");

        $process = $this->lib('tfvars_b64 app_secret; echo; tfvars_value app_encryption_key', []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([base64_encode("line1\nline2\n"), 'abc'], explode("\n", rtrim($process->getOutput())));
    }

    public function test_an_empty_or_unset_value_is_skipped_and_a_set_value_is_not(): void
    {
        file_put_contents($this->dir.'/secrets.vars', self::REQUIRED."app_secret = \"x\"\napp_encryption_key = \"k\"\ninstall_token = \"\"\n");

        $process = $this->lib('skip_if_unset install_token || echo "rc=$?"; skip_if_unset mercure_jwt_secret || echo "rc=$?"; skip_if_unset app_secret && echo "ran"', []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            "install_token: not set, skipped\nrc=1\nmercure_jwt_secret: not set, skipped\nrc=1\nran\n",
            $process->getOutput(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCharacters(): iterable
    {
        yield 'backspace' => ["a\x08b"];
        yield 'form feed' => ["a\x0cb"];
    }

    #[DataProvider('refusedCharacters')]
    public function test_the_writer_refuses_a_character_that_hcl_has_no_escape_for(string $value): void
    {
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->lib('tfvars_write app_secret "$V"', ['V' => $value]);

        self::assertSame(1, $process->getExitCode());
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertStringContainsString('app_secret', $process->getErrorOutput());
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenReadBacks(): iterable
    {
        yield 'terraform fails' => ['exit 1'];
        yield 'terraform reads another value' => ['echo "\"AAAA\""; exit 0'];
    }

    #[DataProvider('brokenReadBacks')]
    public function test_a_failed_read_back_leaves_the_file_whole_and_no_temporary_file(string $stubBody): void
    {
        $real = trim((string) shell_exec('command -v terraform'));
        mkdir($this->dir.'/stub');
        file_put_contents($this->dir.'/stub/terraform', "#!/usr/bin/env bash\ncase \"\$*\" in *.rotate-secrets.tmp.*) {$stubBody} ;; esac\nexec {$real} \"\$@\"\n");
        chmod($this->dir.'/stub/terraform', 0o755);
        $before = (string) file_get_contents($this->dir.'/secrets.vars');
        $secret = 'very-secret-value-123';

        $process = $this->lib('tfvars_write app_secret "$V"', ['V' => $secret, 'PATH' => $this->dir.'/stub:'.getenv('PATH')]);

        self::assertSame(1, $process->getExitCode());
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
        self::assertStringContainsString('app_secret', $process->getErrorOutput());
        self::assertStringNotContainsString($secret, $process->getOutput().$process->getErrorOutput());
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return ['ROTATE_TFVARS' => $this->dir.'/secrets.vars', 'ROTATE_TF_ROOT' => $this->dir.'/tf', 'ROTATE_APPLY' => $this->dir.'/apply.sh'];
    }

    /**
     * Runs shell code with the functions of the script loaded.
     *
     * @param array<string, string> $extraEnv
     */
    private function lib(string $code, array $extraEnv): Process
    {
        $process = new Process(
            ['bash', '-c', 'set -euo pipefail; . "$1"; '.$code, 'bash', \dirname(__DIR__, 2).'/bin/rotate-secrets'],
            env: $extraEnv + $this->env(),
        );
        $process->run();

        return $process;
    }

    private function readWithTerraform(string $name): string
    {
        $process = new Process(
            ['terraform', '-chdir='.$this->dir.'/tf', 'console', '-no-color', '-var-file='.$this->dir.'/secrets.vars'],
            input: "base64encode(nonsensitive(var.{$name}))\n",
        );
        $process->mustRun();
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));

        return trim((string) end($lines), '"');
    }

    /**
     * @param list<string> $names
     */
    private function rotate(array $names, string $input): Process
    {
        $process = new Process(
            [\dirname(__DIR__, 2).'/bin/rotate-secrets', ...$names],
            env: $this->env(),
            input: $input,
        );
        $process->run();

        return $process;
    }
}
