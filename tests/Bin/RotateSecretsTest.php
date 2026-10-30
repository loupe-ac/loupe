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

    // Only for a run that must time out. Bash counts $SECONDS in whole seconds, so one second can end after one poll.
    private const array SHORT_TIMEOUTS = ['ROTATE_POLL_TIMEOUT' => '1', 'ROTATE_RESEND_TIMEOUT' => '1'];

    private string $dir;

    /** @var array<string, string> */
    private array $storeEnv = [];

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
            if [ -n "${TF_SECRETS_STORE:-}" ]; then
                key() { sops decrypt "$TF_SECRETS_STORE" | jq -j --arg n "TF_VAR_$1" '.[$n] // ""'; }
            else
                key() { awk -F'"' -v n="$1" '$0 ~ "^" n "[ \t]*=" {print $2}' "$f"; }
            fi
            cur=$(key app_encryption_key)
            prev=$(key app_encryption_key_previous)
            old=T0xELEtFWS1PTEQtS0VZLU9MRC1LRVktT0xELUtFWS1PTA==
            label() { if [ -z "$1" ]; then echo none; elif [ "$1" = "$old" ]; then echo old; else echo new; fi; }
            log="$(dirname "$0")/applies.log"
            echo "current=$(label "$cur") previous=$(label "$prev")" >> "$log"
            echo apply >> "$(dirname "$0")/events.log"
            [ ! -f "$(dirname "$0")/.rotate-secrets.webhook-since" ] || cp "$(dirname "$0")/.rotate-secrets.webhook-since" "$(dirname "$0")/file-at-apply-$(wc -l < "$log" | tr -d ' ')"
            fail="$(dirname "$0")/fail-at"
            [ ! -f "$fail" ] || [ "$(wc -l < "$log")" -ne "$(cat "$fail")" ] || { rm "$fail"; exit 1; }
            SH);
        chmod($this->dir.'/apply.sh', 0o755);
        // The stub plan records its arguments, and the stub diff reads what a test wants the plan to change.
        file_put_contents($this->dir.'/plan.sh', <<<'SH'
            #!/usr/bin/env bash
            d="$(dirname "$0")"; args=()
            for a in "$@"; do case $a in -input=* | -out=*) ;; *) args+=("$a") ;; esac; done
            [ ${#args[@]} -eq 0 ] || echo "${args[*]}" >> "$d/apply-args.log"
            for a in "$@"; do case $a in -out=*) echo plan > "${a#-out=}" ;; esac; done
            SH);
        chmod($this->dir.'/plan.sh', 0o755);
        file_put_contents($this->dir.'/plan-show.sh', <<<'SH'
            #!/usr/bin/env bash
            f="$(dirname "$0")/plan.json"
            if [ -f "$f" ]; then cat "$f"; else echo '{"resource_changes":[{"address":"module.app.digitalocean_app.app","change":{"actions":["update"],"before":{"spec":[]},"after":{"spec":[]}}}]}'; fi
            SH);
        chmod($this->dir.'/plan-show.sh', 0o755);
        $this->writeStubs();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function test_it_rotates_the_key_in_three_deploys_and_never_prints_a_key(): void
    {
        $process = $this->rotate(['app_encryption_key'], '');

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
        $process = $this->rotate(['app_encryption_key'], '', reencrypted: false);

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
        $this->rotate(['app_encryption_key'], '', reencrypted: false);
        $process = $this->resume();

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
        $first = $this->rotate(['app_encryption_key'], '');
        self::assertNotSame(0, $first->getExitCode());

        $process = $this->resume();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($applies, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES));
        self::assertStringContainsString("resuming a rotation at deploy {$failAt}", $process->getOutput());
    }

    public function test_it_reads_a_quoted_key_that_has_an_inline_comment(): void
    {
        file_put_contents($this->dir.'/secrets.vars', self::REQUIRED."app_secret = \"keep-me\"\napp_encryption_key = \"".self::OLD_KEY."\" # the live key\n");

        $process = $this->rotate(['app_encryption_key'], '', reencrypted: false);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString("\napp_encryption_key_previous = \"".self::OLD_KEY."\"\n", (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_it_replaces_the_file_by_rename_and_keeps_its_mode(): void
    {
        $file = $this->dir.'/secrets.vars';
        chmod($file, 0o600);
        $original = file_get_contents($file);
        // The link holds the old inode, so a later temp file cannot reuse its number.
        link($file, $this->dir.'/original-link');
        $inode = fileinode($file);

        $process = $this->rotate(['app_encryption_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        clearstatcache();
        self::assertNotSame($inode, fileinode($file));
        self::assertSame($original, file_get_contents($this->dir.'/original-link'));
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame([$file], glob($file.'*'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
    }

    public function test_it_refuses_to_start_while_a_previous_key_is_set_with_no_saved_rotation(): void
    {
        file_put_contents($this->dir.'/secrets.vars', "app_encryption_key_previous = \"other\"\n", \FILE_APPEND);

        $process = $this->rotate([], '');

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
        $process = $this->rotate(['app_encryption_key_previous', 'app_encryption_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertCount(3, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function test_an_unknown_secret_lists_the_known_names(): void
    {
        $process = $this->rotate(['no_such_secret'], '');

        self::assertStringContainsString('Known: app_encryption_key app_encryption_key_previous', $process->getErrorOutput());
    }

    public function test_the_impact_groups_hold_every_step_of_the_full_run_once_and_no_web_push_key(): void
    {
        $process = $this->lib('echo "${WALK[*]}"; for g in "${IMPACT_GROUPS[@]}"; do group_steps "$g"; done', []);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));
        $walk = explode(' ', array_shift($lines));
        $grouped = explode(' ', implode(' ', $lines));
        self::assertCount(3, $lines);
        self::assertSame(array_unique($grouped), $grouped, 'a step is in two groups');
        sort($walk);
        sort($grouped);
        self::assertSame($walk, $grouped);
        self::assertNotContains('vapid', $grouped);
    }

    public function test_a_run_by_impact_group_runs_the_encryption_key_first_and_names_the_groups_it_left_out(): void
    {
        $this->vars("install_token = \"x\"\noauth_encryption_key = \"x\"\n");

        $process = $this->rotate(['@no-relogin'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old', 'current=new previous=none', 'current=new previous=none'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->value('install_token'));
        self::assertSame('x', $this->value('oauth_encryption_key'));
        self::assertSame('keep-me', $this->value('app_secret'));
        $output = $process->getOutput();
        self::assertLessThan(strpos($output, 'export_bucket_key:'), strpos($output, 'app_encryption_key: made a new key'));
        self::assertStringContainsString("\nNot rotated in full: @relogin-users @relogin-clients\n", $output);
    }

    public function test_a_run_by_the_users_group_rotates_the_app_secret_and_asks_to_empty_the_sessions(): void
    {
        $process = $this->rotate(['@relogin-users'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(['current=old previous=none'], file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->value('app_secret'));
        self::assertStringContainsString("bin/console dbal:run-sql 'DELETE FROM sessions'", $process->getOutput());
        self::assertStringContainsString("\nNot rotated in full: @no-relogin @relogin-clients\n", $process->getOutput());
    }

    public function test_a_run_by_the_clients_group_rotates_the_oauth_keys_and_the_oauth_encryption_key_in_one_apply(): void
    {
        $this->vars('oauth_private_key = '.$this->hcl($this->pem())."\noauth_private_key_passphrase = \"\"\noauth_public_key = \"old-public\"\noauth_encryption_key = \"x\"\n");

        $process = $this->rotate(['@relogin-clients'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        self::assertNotSame('old-public', $this->value('oauth_public_key'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->value('oauth_encryption_key'));
        self::assertSame('keep-me', $this->value('app_secret'));
        self::assertStringNotContainsString('DELETE FROM sessions', $process->getOutput());
        self::assertStringContainsString("\nNot rotated in full: @no-relogin @relogin-users\n", $process->getOutput());
    }

    public function test_names_that_fill_a_group_cover_it_and_a_skipped_app_secret_asks_nothing(): void
    {
        $this->vars("oauth_encryption_key = \"x\"\n");
        file_put_contents($this->dir.'/secrets.vars', str_replace('app_secret = "keep-me"', 'app_secret = ""', (string) file_get_contents($this->dir.'/secrets.vars')));

        $process = $this->rotate(['oauth_encryption_key', 'oauth_public_key', 'app_secret'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("app_secret: not set, skipped\n", $process->getOutput());
        self::assertStringNotContainsString('DELETE FROM sessions', $process->getOutput());
        self::assertStringContainsString("\nNot rotated in full: @no-relogin\n", $process->getOutput());
    }

    public function test_a_web_push_run_covers_no_impact_group(): void
    {
        $this->vars("vapid_private_key = \"old-private\"\nvapid_public_key = \"old-public\"\n");

        $process = $this->rotate(['vapid_private_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("\nNot rotated in full: @no-relogin @relogin-users @relogin-clients\n", $process->getOutput());
    }

    public function test_an_unknown_impact_group_is_refused_before_any_apply_and_the_groups_are_listed(): void
    {
        $process = $this->rotate(['app_secret', '@no-such-group'], '');

        self::assertSame(1, $process->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertStringContainsString("unknown group '@no-such-group'. Groups: @no-relogin @relogin-users @relogin-clients", $process->getErrorOutput());
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

    public function test_it_makes_the_local_secrets_in_one_write_and_one_apply(): void
    {
        $this->vars("install_token = \"x\"\nhealth_probe_token = \"x\"\nmercure_jwt_secret = \"x\"\noauth_encryption_key = \"x\"\n");

        $process = $this->rotate(['app_secret', 'install_token', 'health_probe_token', 'mercure_jwt_secret', 'oauth_encryption_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(['current=old previous=none'], file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->value('app_secret'));
        foreach (['install_token', 'health_probe_token', 'mercure_jwt_secret', 'oauth_encryption_key'] as $name) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->value($name), $name);
        }
        self::assertNotSame($this->value('install_token'), $this->value('health_probe_token'));
        $output = $process->getOutput().$process->getErrorOutput();
        foreach (['app_secret', 'install_token', 'health_probe_token', 'mercure_jwt_secret', 'oauth_encryption_key'] as $name) {
            self::assertStringNotContainsString($this->value($name), $output);
        }
        self::assertStringContainsString('app_encryption_key = "'.self::OLD_KEY.'"', (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_an_unset_or_empty_local_secret_is_skipped_and_nothing_is_applied(): void
    {
        $this->vars("install_token = \"\"\n");
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->rotate(['install_token', 'mercure_jwt_secret'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("install_token: not set, skipped\n", $process->getOutput());
        self::assertStringContainsString("mercure_jwt_secret: not set, skipped\n", $process->getOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_it_makes_a_new_unencrypted_oauth_key_pair_when_the_passphrase_is_empty(): void
    {
        $old = $this->pem();
        $this->vars('oauth_private_key = '.$this->hcl($old)."\noauth_private_key_passphrase = \"\"\noauth_public_key = \"old-public\"\n");

        $process = $this->rotate(['oauth_public_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $private = $this->value('oauth_private_key');
        self::assertNotSame($old, $private);
        self::assertSame('', $this->value('oauth_private_key_passphrase'));
        $key = openssl_pkey_get_private($private);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(2048, $details['bits']);
        self::assertSame(trim($details['key']), trim($this->value('oauth_public_key')));
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        self::assertStringNotContainsString(trim($private), $process->getOutput().$process->getErrorOutput());
    }

    public function test_it_encrypts_the_new_oauth_key_with_a_new_passphrase_when_the_old_one_is_set(): void
    {
        $this->vars('oauth_private_key = '.$this->hcl($this->pem('old-pass'))."\noauth_private_key_passphrase = \"old-pass\"\noauth_public_key = \"old-public\"\n");

        $process = $this->rotate(['oauth_private_key_passphrase'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $pass = $this->value('oauth_private_key_passphrase');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pass);
        $key = openssl_pkey_get_private($this->value('oauth_private_key'), $pass);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(trim($details['key']), trim($this->value('oauth_public_key')));
        self::assertStringNotContainsString($pass, $process->getOutput().$process->getErrorOutput());
    }

    public function test_it_replaces_the_export_bucket_key_through_the_apply(): void
    {
        $process = $this->rotate(['export_bucket_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(['-replace=digitalocean_spaces_key.exports[0]'], file($this->dir.'/apply-args.log', \FILE_IGNORE_NEW_LINES));
    }

    public function test_it_skips_the_export_bucket_key_when_terraform_does_not_make_the_bucket(): void
    {
        $this->vars("create_export_bucket = false\n");

        $process = $this->rotate(['export_bucket_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('export_bucket_key: create_export_bucket is false, skipped', $process->getOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_the_replace_flag_joins_the_one_apply_of_the_other_steps(): void
    {
        $process = $this->rotate(['export_bucket_key', 'app_secret'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(['-replace=digitalocean_spaces_key.exports[0]'], file($this->dir.'/apply-args.log', \FILE_IGNORE_NEW_LINES));
        self::assertNotSame('keep-me', $this->value('app_secret'));
    }

    public function test_the_webhook_secret_switches_after_a_new_deployment_and_failed_events_are_sent_again(): void
    {
        $this->webhookVars();
        $this->deployments(['dep-old', 'dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z'], ['dep-old', 'SUPERSEDED', '2000-01-01T00:00:00Z']]);
        $this->deliveries([
            [
                $this->delivery(11, 'g-failed', 502, 'failed to connect'),
                $this->delivery(12, 'g-ok', 200, 'OK'),
                $this->delivery(13, 'g-bad-signature', 400, 'Invalid HTTP Response: 400'),
                $this->delivery(14, 'g-redirect', 302, 'Found'),
                $this->delivery(15, 'g-old', 502, 'failed to connect', '2000-01-01T00:00:00Z'),
                $this->delivery(40, 'g-late', 502, 'failed to connect'),
                $this->delivery(41, 'g-late', 200, 'OK'),
            ],
            [
                $this->delivery(21, 'g-failed', 200, 'OK'),
                $this->delivery(22, 'g-bad-signature', 200, 'OK'),
                $this->delivery(11, 'g-failed', 502, 'failed to connect'),
                $this->delivery(13, 'g-bad-signature', 400, 'Invalid HTTP Response: 400'),
            ],
        ]);

        $process = $this->rotate(['github_app_webhook_secret'], '');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $new = $this->value('github_app_webhook_secret');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $new);
        self::assertNotSame('old-webhook-secret', $new);
        $events = file($this->dir.'/events.log', \FILE_IGNORE_NEW_LINES) ?: [];
        $apply = array_search('apply', $events, true);
        $patch = array_search('curl PATCH https://api.github.test/app/hook/config', $events, true);
        self::assertNotFalse($apply);
        self::assertNotFalse($patch);
        self::assertLessThan($patch, $apply);
        $attempts = file($this->dir.'/attempts.log', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($attempts);
        sort($attempts);
        self::assertSame(['https://api.github.test/app/hook/deliveries/11/attempts', 'https://api.github.test/app/hook/deliveries/13/attempts'], $attempts);
        self::assertSame($new, json_decode((string) file_get_contents($this->dir.'/patch-body'), true)['secret'] ?? null);
        $atApply = file($this->dir.'/file-at-apply-1', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($atApply);
        self::assertCount(3, $atApply);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $atApply[0]);
        self::assertSame(['applying', 'dep-old'], \array_slice($atApply, 1));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.webhook-since');
        self::assertStringNotContainsString($new, $process->getOutput().$process->getErrorOutput());
        self::assertStringNotContainsString('3 days', $process->getOutput().$process->getErrorOutput());
        $this->assertValidAppJwt();
    }

    public function test_a_failed_apply_leaves_the_phase_applying_and_calls_nothing_at_github_and_a_retry_applies_again(): void
    {
        $this->webhookVars();
        $this->deployments(['dep-old', 'dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
        $this->deliveries([[]]);
        file_put_contents($this->dir.'/fail-at', '1');

        $first = $this->rotate(['github_app_webhook_secret'], '');

        self::assertNotSame(0, $first->getExitCode());
        self::assertSame(['applying', 'dep-old'], \array_slice(file($this->dir.'/.rotate-secrets.webhook-since', \FILE_IGNORE_NEW_LINES) ?: [], 1));
        self::assertStringContainsString('bin/rotate-secrets apply', $first->getErrorOutput());
        self::assertStringNotContainsString('curl', (string) file_get_contents($this->dir.'/events.log'));
        $secret = $this->value('github_app_webhook_secret');
        $start = (file($this->dir.'/.rotate-secrets.webhook-since', \FILE_IGNORE_NEW_LINES) ?: [])[0];
        file_put_contents($this->dir.'/active', "dep-new\n");

        $second = $this->resume();

        self::assertSame(0, $second->getExitCode(), $second->getOutput().$second->getErrorOutput());
        self::assertSame($secret, $this->value('github_app_webhook_secret'));
        self::assertCount(2, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame($secret, json_decode((string) file_get_contents($this->dir.'/patch-body'), true)['secret'] ?? null);
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.webhook-since');
        self::assertNotSame('', $start);
    }

    public function test_a_failed_switch_keeps_the_file_and_a_retry_makes_no_secret_and_no_apply(): void
    {
        $this->webhookVars();
        $this->deployments(['dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
        $this->deliveries([[$this->delivery(31, 'g1', 502, 'failed to connect')], [$this->delivery(32, 'g1', 200, 'OK'), $this->delivery(31, 'g1', 502, 'failed to connect')]]);
        touch($this->dir.'/patch-fail');

        $first = $this->rotate(['github_app_webhook_secret'], '');

        self::assertNotSame(0, $first->getExitCode());
        $lines = file($this->dir.'/.rotate-secrets.webhook-since', \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame(['applied', 'dep-old'], \array_slice($lines, 1));
        self::assertStringContainsString('bin/rotate-secrets apply', $first->getErrorOutput());
        $secret = $this->value('github_app_webhook_secret');
        unlink($this->dir.'/patch-fail');
        unlink($this->dir.'/patch-body');

        $second = $this->resume();

        self::assertSame(0, $second->getExitCode(), $second->getOutput().$second->getErrorOutput());
        self::assertSame($secret, $this->value('github_app_webhook_secret'));
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        self::assertSame($secret, json_decode((string) file_get_contents($this->dir.'/patch-body'), true)['secret'] ?? null);
        self::assertSame(['https://api.github.test/app/hook/deliveries/31/attempts'], file($this->dir.'/attempts.log', \FILE_IGNORE_NEW_LINES));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.webhook-since');
    }

    public function test_the_poll_keeps_waiting_while_the_old_deployment_is_active_and_calls_nothing_at_github(): void
    {
        $this->webhookVars();
        $this->deployments(['dep-old'], [['dep-old', 'ACTIVE', '2000-01-01T00:00:00Z']]);
        $this->deliveries([[]]);

        $process = $this->rotate(['github_app_webhook_secret'], '', self::SHORT_TIMEOUTS);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringNotContainsString('curl', (string) file_get_contents($this->dir.'/events.log'));
        self::assertSame('applying', (file($this->dir.'/.rotate-secrets.webhook-since', \FILE_IGNORE_NEW_LINES) ?: [])[1] ?? null);
        self::assertStringContainsString('bin/rotate-secrets apply', $process->getErrorOutput());
        self::assertGreaterThan(1, substr_count((string) file_get_contents($this->dir.'/doctl.log'), 'apps get'));
    }

    public function test_a_new_deployment_in_the_error_phase_stops_the_run_and_names_it(): void
    {
        $this->webhookVars();
        $this->deployments(['dep-old'], [['dep-bad', 'ERROR', '2099-01-01T00:00:00Z'], ['dep-old', 'ACTIVE', '2000-01-01T00:00:00Z']]);
        $this->deliveries([[]]);

        $process = $this->rotate(['github_app_webhook_secret'], '');

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('dep-bad', $process->getErrorOutput());
        self::assertStringContainsString('ERROR', $process->getErrorOutput());
        self::assertStringNotContainsString('curl', (string) file_get_contents($this->dir.'/events.log'));
        self::assertSame('applying', (file($this->dir.'/.rotate-secrets.webhook-since', \FILE_IGNORE_NEW_LINES) ?: [])[1] ?? null);
    }

    public function test_a_retry_whose_recorded_deployment_differs_from_the_active_one_passes_the_poll_and_warns_about_old_events(): void
    {
        $this->webhookVars();
        file_put_contents($this->dir.'/.rotate-secrets.webhook-since', "2026-01-01T00:00:00Z\napplying\ndep-recorded\n");
        $this->deployments(['dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
        $this->deliveries([[$this->delivery(51, 'g1', 502, 'failed to connect')], [$this->delivery(52, 'g1', 200, 'OK')]]);

        $process = $this->resume();
        $output = $process->getOutput().$process->getErrorOutput();

        self::assertStringContainsString('more than 3 days', $output);
        self::assertSame($this->value('github_app_webhook_secret'), json_decode((string) file_get_contents($this->dir.'/patch-body'), true)['secret'] ?? null);
        self::assertSame('old-webhook-secret', $this->value('github_app_webhook_secret'));
        self::assertSame(['https://api.github.test/app/hook/deliveries/51/attempts'], file($this->dir.'/attempts.log', \FILE_IGNORE_NEW_LINES));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.webhook-since');
        $events = file($this->dir.'/events.log', \FILE_IGNORE_NEW_LINES) ?: [];
        $patch = array_search('curl PATCH https://api.github.test/app/hook/config', $events, true);
        $applies = array_keys($events, 'apply', true);
        self::assertNotFalse($patch);
        self::assertCount(1, array_filter($applies, static fn (int $i): bool => $i < $patch));
    }

    public function test_a_resend_that_github_accepts_and_that_then_fails_keeps_the_file_and_a_retry_resends_the_new_delivery(): void
    {
        $this->webhookVars();
        $this->deployments(['dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
        $failed = [$this->delivery(21, 'g1', 502, 'failed to connect')];
        $stillFailed = [$this->delivery(22, 'g1', 502, 'failed to connect'), $this->delivery(21, 'g1', 502, 'failed to connect')];
        $this->deliveries([$failed, $stillFailed]);

        $first = $this->rotate(['github_app_webhook_secret'], '', self::SHORT_TIMEOUTS);

        self::assertNotSame(0, $first->getExitCode());
        self::assertSame(['https://api.github.test/app/hook/deliveries/21/attempts'], file($this->dir.'/attempts.log', \FILE_IGNORE_NEW_LINES));
        $lines = file($this->dir.'/.rotate-secrets.webhook-since', \FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame('applied', $lines[1] ?? null);
        self::assertStringContainsString('1 event', $first->getErrorOutput());
        self::assertStringContainsString('bin/rotate-secrets apply', $first->getErrorOutput());
        unlink($this->dir.'/attempts.log');
        $this->deliveries([$stillFailed, [$this->delivery(23, 'g1', 200, 'OK'), ...$stillFailed]]);

        $second = $this->resume();

        self::assertSame(0, $second->getExitCode(), $second->getOutput().$second->getErrorOutput());
        self::assertSame(['https://api.github.test/app/hook/deliveries/22/attempts'], file($this->dir.'/attempts.log', \FILE_IGNORE_NEW_LINES));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.webhook-since');
    }

    #[DataProvider('webhookFailures')]
    public function test_a_webhook_failure_after_the_apply_still_lists_the_old_vendor_credentials(string $failure): void
    {
        $this->vars("do_token = \"old-do\"\ngithub_app_webhook_secret = \"old-webhook-secret\"\ngithub_app_id = \"12345\"\ngithub_app_private_key = ".$this->hcl($this->pem())."\n");
        $this->deliveries([[]]);
        if ('patch' === $failure) {
            $this->deployments(['dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
            touch($this->dir.'/patch-fail');
        } else {
            $this->deployments(['dep-old'], [['dep-bad', 'ERROR', '2099-01-01T00:00:00Z'], ['dep-old', 'ACTIVE', '2000-01-01T00:00:00Z']]);
        }

        $process = $this->rotate(['do_token', 'github_app_webhook_secret'], "new-do\n");

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame('new-do', $this->value('do_token'));
        self::assertSame(1, substr_count($process->getOutput(), 'Revoke by hand:'));
        [, $revoke] = explode('Revoke by hand:', $process->getOutput()) + [1 => ''];
        self::assertStringContainsString('do_token', $revoke);
        self::assertStringNotContainsString('old-do', $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_webhook_failure_after_the_apply_still_asks_to_empty_the_sessions(): void
    {
        $this->webhookVars();
        $this->deliveries([[]]);
        $this->deployments(['dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
        touch($this->dir.'/patch-fail');

        $process = $this->rotate(['app_secret', 'github_app_webhook_secret'], '');

        self::assertNotSame(0, $process->getExitCode());
        self::assertNotSame('keep-me', $this->value('app_secret'));
        self::assertStringContainsString("bin/console dbal:run-sql 'DELETE FROM sessions'", $process->getOutput());
    }

    public function test_a_pending_webhook_switch_blocks_a_new_collect_until_apply_finishes_it(): void
    {
        $this->webhookVars();
        $this->deliveries([[]]);
        $this->deployments(['dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z']]);
        touch($this->dir.'/patch-fail');
        $first = $this->rotate(['github_app_webhook_secret'], '');
        self::assertNotSame(0, $first->getExitCode());
        unlink($this->dir.'/patch-fail');
        file_put_contents($this->dir.'/new-app-key.pem', $this->pem());

        $refused = $this->rotate(['@no-relogin'], $this->dir."/new-app-key.pem\n");
        $resumed = $this->resume();
        $this->deployments(['dep-new', 'dep-newer'], [['dep-newer', 'ACTIVE', '2099-01-02T00:00:00Z']]);
        $this->deliveries([[]]);
        $process = $this->rotate(['@no-relogin'], $this->dir."/new-app-key.pem\n");

        self::assertNotSame(0, $refused->getExitCode());
        self::assertStringContainsString('a rotation is pending', $refused->getErrorOutput());
        self::assertSame(0, $resumed->getExitCode(), $resumed->getOutput().$resumed->getErrorOutput());
        self::assertStringContainsString('finishing the switch', $resumed->getOutput());
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString("\nNot rotated in full: @relogin-users @relogin-clients\n", $process->getOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function webhookFailures(): iterable
    {
        yield 'GitHub refuses the new secret' => ['patch'];
        yield 'the new deployment fails' => ['deployment'];
    }

    public function test_the_webhook_secret_needs_the_app_id_and_key_before_anything_is_written(): void
    {
        $this->vars("github_app_webhook_secret = \"old-webhook-secret\"\n");
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->rotate(['github_app_webhook_secret'], '');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('github_app_id', $process->getErrorOutput());
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_a_vendor_value_is_read_hidden_written_and_listed_for_revoking(): void
    {
        $this->vars("do_token = \"old-do\"\nstripe_secret_key = \"old-stripe\"\nregistry_credentials = \"user:old-ghcr\"\n");

        $process = $this->rotate(['do_token', 'stripe_secret_key', 'registry_credentials', 'app_secret'], "new-do\nuser:new-ghcr\nnew-stripe\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('new-do', $this->value('do_token'));
        self::assertSame('new-stripe', $this->value('stripe_secret_key'));
        self::assertSame('user:new-ghcr', $this->value('registry_credentials'));
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        $output = $process->getOutput().$process->getErrorOutput();
        foreach (['new-do', 'new-stripe', 'new-ghcr', 'old-do', 'old-stripe', 'old-ghcr'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
        self::assertStringContainsString("  https://cloud.digitalocean.com/account/api/tokens\n", $output);
        self::assertStringContainsString("  https://dashboard.stripe.com/apikeys\n", $output);
        [, $revoke] = explode('Revoke by hand:', $output) + [1 => ''];
        self::assertStringContainsString('do_token', $revoke);
        self::assertStringContainsString('stripe_secret_key', $revoke);
        self::assertStringContainsString('GHCR token', $revoke);
        self::assertStringNotContainsString('app_secret', $revoke);
    }

    public function test_a_multi_line_value_is_read_until_a_line_with_only_a_period(): void
    {
        $this->vars('github_app_private_key = '.$this->hcl($this->pem())."\n");
        $typed = "-----BEGIN RSA PRIVATE KEY-----\nabc/def+ghi==\n-----END RSA PRIVATE KEY-----\n";

        $process = $this->rotate(['github_app_private_key'], $typed.".\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($typed, $this->value('github_app_private_key'));
        self::assertStringContainsString('old GitHub App private key', $process->getOutput());
        self::assertStringNotContainsString('abc/def', $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_multi_line_value_can_come_from_a_file_path(): void
    {
        $this->vars('github_app_private_key = '.$this->hcl($this->pem())."\n");
        $new = $this->pem();
        file_put_contents($this->dir.'/new-key.pem', $new);

        $process = $this->rotate(['github_app_private_key'], $this->dir."/new-key.pem\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame($new, $this->value('github_app_private_key'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badShapes(): iterable
    {
        yield 'empty' => ['do_token', "\n", 'empty'];
        yield 'control character' => ['do_token', "ab\x01cd\n", 'control character'];
        yield 'carriage return' => ['do_token', "abcd\r\n", 'control character'];
        yield 'no pem line' => ['github_app_private_key', "just text\n.\n", 'PEM'];
        yield 'registry without colon' => ['registry_credentials', "tokenonly\n", 'user:token'];
    }

    #[DataProvider('badShapes')]
    public function test_a_badly_shaped_vendor_value_stops_the_run_before_the_write(string $name, string $input, string $reason): void
    {
        $this->vars("do_token = \"old-do\"\nregistry_credentials = \"user:old\"\ngithub_app_private_key = \"old-key\"\n");
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->rotate([$name], $input);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString($name, $process->getErrorOutput());
        self::assertStringContainsString($reason, $process->getErrorOutput());
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_the_mailer_dsn_takes_only_the_new_password_url_encoded(): void
    {
        $this->vars("mailer_dsn = \"smtp://user:oldpw@smtp.example.com:587?encryption=tls\"\n");

        $process = $this->rotate(['mailer_dsn'], "p@ss w/rd:1\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('smtp://user:p%40ss%20w%2Frd%3A1@smtp.example.com:587?encryption=tls', $this->value('mailer_dsn'));
        self::assertStringNotContainsString('oldpw', $process->getOutput().$process->getErrorOutput());
        self::assertStringNotContainsString('w/rd', $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_dsn_without_a_password_is_skipped(): void
    {
        $process = $this->rotate(['mailer_dsn'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('mailer_dsn: the DSN has no password, skipped', $process->getOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_an_unset_vendor_secret_is_skipped_without_a_prompt(): void
    {
        $process = $this->rotate(['stripe_webhook_secret', 'site_review_widget_preview_token'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString("stripe_webhook_secret: not set, skipped\n", $process->getOutput());
        self::assertStringContainsString("site_review_widget_preview_token: not set, skipped\n", $process->getOutput());
    }

    public function test_a_new_web_push_pair_is_made_only_by_name_and_neither_key_is_printed(): void
    {
        $this->vars("vapid_private_key = \"old-private\"\nvapid_public_key = \"old-public\"\n");

        $process = $this->rotate(['vapid_private_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('new-vapid-private', $this->value('vapid_private_key'));
        self::assertSame('new-vapid-public', $this->value('vapid_public_key'));
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertStringNotContainsString('new-vapid', $output);
        self::assertStringNotContainsString('old-p', $output);
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function test_a_run_with_no_names_skips_the_web_push_key_and_says_so(): void
    {
        $this->vars("vapid_private_key = \"old-private\"\nvapid_public_key = \"old-public\"\n");

        $process = $this->rotate([], '');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertSame('old-private', $this->value('vapid_private_key'));
        self::assertStringContainsString('vapid_private_key: skipped, rotate it by name; every push subscription stops', $process->getOutput());
        self::assertNotSame('keep-me', $this->value('app_secret'));
        self::assertStringContainsString("bin/console dbal:run-sql 'DELETE FROM sessions'", $process->getOutput());
        self::assertStringNotContainsString('Not rotated in full', $process->getOutput());
    }

    public function test_a_web_push_key_command_that_prints_no_pair_stops_the_run_and_prints_nothing_of_it(): void
    {
        $this->vars("vapid_private_key = \"old-private\"\n");
        file_put_contents($this->dir.'/vapid.sh', "#!/usr/bin/env bash\necho SECRET-NOISE\n");

        $process = $this->rotate(['vapid_private_key'], '');

        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('SECRET-NOISE', $process->getOutput().$process->getErrorOutput());
        self::assertSame('old-private', $this->value('vapid_private_key'));
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_an_unset_web_push_key_is_skipped_even_by_name(): void
    {
        $process = $this->rotate(['vapid_private_key'], '');

        self::assertStringContainsString("vapid_private_key: not set, skipped\n", $process->getOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_a_listed_secret_is_read_from_and_written_to_the_store_and_the_file_is_left_alone(): void
    {
        $this->useStore(['TF_VAR_app_secret' => "old\nvalue", 'TF_VAR_install_token' => '']);
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->lib('tfvars_b64 app_secret; echo; tfvars_write app_secret "$V"', ['V' => "new\n\"value\"\n"]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(base64_encode("old\nvalue"), explode("\n", $process->getOutput())[0]);
        self::assertSame("new\n\"value\"\n", $this->stored('TF_VAR_app_secret'));
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.tmp.*'));
    }

    public function test_a_split_write_sends_the_secret_to_the_store_and_the_public_half_to_the_file(): void
    {
        $this->useStore(['TF_VAR_oauth_private_key' => 'old']);

        $process = $this->lib('tfvars_write oauth_private_key "$K" oauth_public_key "$P"', ['K' => 'private', 'P' => 'public']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('private', $this->stored('TF_VAR_oauth_private_key'));
        $file = (string) file_get_contents($this->dir.'/secrets.vars');
        self::assertStringContainsString('oauth_public_key = "public"', $file);
        self::assertStringNotContainsString('private', $file);
    }

    public function test_a_failed_file_write_after_a_store_write_leaves_the_new_secret_in_the_store_and_the_file_whole(): void
    {
        $this->useStore(['TF_VAR_oauth_private_key' => 'old']);
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->lib('tfvars_write oauth_private_key "new" oauth_public_key "$P"', ['P' => "a\x08b"]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertSame('new', $this->stored('TF_VAR_oauth_private_key'));
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_a_failed_read_back_leaves_the_store_unchanged(): void
    {
        $this->useStore(['TF_VAR_app_secret' => 'old']);

        $process = $this->lib('tfvars_write app_secret "new"', ['FAKE_SOPS_CORRUPT' => '1']);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString('did not read back the same', $process->getErrorOutput());
        self::assertSame('old', $this->stored('TF_VAR_app_secret'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.tmp.*'));
        self::assertStringNotContainsString('new', $process->getOutput().$process->getErrorOutput());
    }

    public function test_the_webhook_secret_reads_its_new_value_from_the_store(): void
    {
        $this->useStore(['TF_VAR_github_app_webhook_secret' => 'before']);

        $process = $this->lib('tfvars_write github_app_webhook_secret "after"; tfvars_value github_app_webhook_secret', []);

        self::assertSame('after', $process->getOutput());
    }

    public function test_a_run_by_name_rotates_the_local_secrets_in_the_store_and_applies_once(): void
    {
        $names = ['app_secret', 'install_token', 'health_probe_token', 'mercure_jwt_secret', 'oauth_encryption_key'];
        $this->useStore(array_combine(array_map(static fn (string $n): string => 'TF_VAR_'.$n, $names), array_map(static fn (string $n): string => 'old-'.$n, $names)));

        $process = $this->rotate($names, '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(1, substr_count((string) file_get_contents($this->dir.'/events.log'), 'apply'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->stored('TF_VAR_app_secret'));
        $output = $process->getOutput().$process->getErrorOutput();
        foreach ($names as $name) {
            $value = $this->stored('TF_VAR_'.$name);
            self::assertNotSame('old-'.$name, $value, $name);
            self::assertStringNotContainsString($value, $output);
        }
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->stored('TF_VAR_install_token'));
        self::assertStringContainsString('app_secret = "keep-me"', (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_the_encryption_key_rotates_in_the_store_in_three_deploys(): void
    {
        $this->useStore(['TF_VAR_app_encryption_key' => self::OLD_KEY]);
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->rotate(['app_encryption_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old', 'current=new previous=none'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
        $new = $this->stored('TF_VAR_app_encryption_key');
        self::assertNotSame('', $new);
        self::assertNotSame(self::OLD_KEY, $new);
        self::assertSame('', $this->stored('TF_VAR_app_encryption_key_previous'));
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertStringNotContainsString(self::OLD_KEY, $output);
        self::assertStringNotContainsString($new, $output);
    }

    public function test_the_oauth_key_pair_puts_the_private_half_in_the_store_and_the_public_half_in_the_file(): void
    {
        $old = $this->pem();
        $this->useStore(['TF_VAR_oauth_private_key' => $old, 'TF_VAR_oauth_private_key_passphrase' => '']);
        $this->vars("oauth_public_key = \"old-public\"\n");

        $process = $this->rotate(['oauth_public_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $private = $this->stored('TF_VAR_oauth_private_key');
        self::assertNotSame($old, $private);
        self::assertSame('', $this->stored('TF_VAR_oauth_private_key_passphrase'));
        $key = openssl_pkey_get_private($private);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(trim($details['key']), trim($this->value('oauth_public_key')));
        self::assertStringNotContainsString('PRIVATE KEY', (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        self::assertStringNotContainsString(trim($private), $process->getOutput().$process->getErrorOutput());
    }

    public function test_vendor_values_are_read_from_and_written_to_the_store_under_their_environment_names(): void
    {
        $this->useStore(['DIGITALOCEAN_TOKEN' => 'old-do', 'TF_VAR_stripe_secret_key' => 'old-stripe', 'TF_VAR_registry_credentials' => 'user:old-ghcr']);
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->rotate(['do_token', 'stripe_secret_key', 'registry_credentials'], "new-do\nuser:new-ghcr\nnew-stripe\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('new-do', $this->stored('DIGITALOCEAN_TOKEN'));
        self::assertSame('new-stripe', $this->stored('TF_VAR_stripe_secret_key'));
        self::assertSame('user:new-ghcr', $this->stored('TF_VAR_registry_credentials'));
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        $output = $process->getOutput().$process->getErrorOutput();
        foreach (['new-do', 'new-stripe', 'new-ghcr', 'old-do', 'old-stripe', 'old-ghcr'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
        [, $revoke] = explode('Revoke by hand:', $output) + [1 => ''];
        self::assertStringContainsString('do_token', $revoke);
        self::assertStringContainsString('stripe_secret_key', $revoke);
    }

    public function test_the_spaces_key_puts_the_secret_in_the_store_and_the_key_id_in_the_file(): void
    {
        $this->useStore(['SPACES_SECRET_ACCESS_KEY' => 'old-spaces-secret']);
        $this->vars("spaces_access_id = \"old-spaces-id\"\n");

        $process = $this->rotate(['spaces_access_id', 'spaces_secret_key'], "new-spaces-id\nnew-spaces-secret\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('new-spaces-secret', $this->stored('SPACES_SECRET_ACCESS_KEY'));
        self::assertSame('', $this->stored('SPACES_ACCESS_KEY_ID'));
        self::assertSame('', $this->stored('TF_VAR_spaces_access_id'));
        self::assertSame('new-spaces-id', $this->value('spaces_access_id'));
        self::assertStringNotContainsString('spaces-secret', (string) file_get_contents($this->dir.'/secrets.vars'));
    }

    public function test_the_menu_enters_a_value_again_and_leaves_out_a_skipped_one(): void
    {
        $this->vars("do_token = \"old-do\"\nregistry_credentials = \"user:old-ghcr\"\nmailer_dsn = \"smtp://user:oldpw@smtp.example.com:587\"\nstripe_secret_key = \"old-stripe\"\n");

        $process = $this->rotate(['do_token', 'registry_credentials', 'mailer_dsn', 'stripe_secret_key'], "wrong-do\nuser:new-ghcr\np@ss\n\n1\nnew-do\ns\n", ['ROTATE_MENU' => '1']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('new-do', $this->value('do_token'));
        self::assertSame('user:new-ghcr', $this->value('registry_credentials'));
        self::assertSame('smtp://user:p%40ss@smtp.example.com:587', $this->value('mailer_dsn'));
        self::assertSame('old-stripe', $this->value('stripe_secret_key'));
        $output = $process->getOutput().$process->getErrorOutput();
        foreach (['wrong-do', 'new-do', 'new-ghcr', 'p@ss', 'old-stripe'] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
        self::assertMatchesRegularExpression('/4\) stripe_secret_key +skipped/', $output);
        self::assertStringContainsString('stripe_secret_key: skipped, not rotated', $output);
        [, $revoke] = explode('Revoke by hand:', $output) + [1 => ''];
        self::assertStringContainsString('The old value of do_token.', $revoke);
        self::assertStringNotContainsString('The old value of stripe_secret_key.', $revoke);
    }

    public function test_the_menu_asks_again_for_a_value_of_the_wrong_form(): void
    {
        $this->vars("registry_credentials = \"user:old-ghcr\"\n");

        $process = $this->rotate(['registry_credentials'], "no-colon\nuser:new-ghcr\ns\n", ['ROTATE_MENU' => '1']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('registry_credentials: the new value needs the form user:token. Type it again', $process->getOutput());
        self::assertSame('user:new-ghcr', $this->value('registry_credentials'));
    }

    public function test_quitting_the_menu_saves_nothing(): void
    {
        $this->vars("do_token = \"old-do\"\n");
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->rotate(['do_token'], "new-do\nq\n", ['ROTATE_MENU' => '1']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('quit. Nothing was saved.', $process->getErrorOutput());
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.pending');
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_the_web_push_pair_puts_the_private_key_in_the_store_and_the_public_key_in_the_file(): void
    {
        $this->useStore(['TF_VAR_vapid_private_key' => 'old-private']);
        $this->vars("vapid_public_key = \"old-public\"\n");

        $process = $this->rotate(['vapid_private_key'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('new-vapid-private', $this->stored('TF_VAR_vapid_private_key'));
        self::assertSame('new-vapid-public', $this->value('vapid_public_key'));
        self::assertStringNotContainsString('vapid-private', (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertStringNotContainsString('new-vapid', $process->getOutput().$process->getErrorOutput());
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function test_the_webhook_secret_rotates_in_the_store_and_github_gets_the_stored_value(): void
    {
        $this->useStore(['TF_VAR_github_app_webhook_secret' => 'old-webhook-secret', 'TF_VAR_github_app_private_key' => $this->pem()]);
        $this->vars("github_app_id = \"12345\"\n");
        $before = (string) file_get_contents($this->dir.'/secrets.vars');
        $this->deployments(['dep-old', 'dep-new'], [['dep-new', 'ACTIVE', '2099-01-01T00:00:00Z'], ['dep-old', 'SUPERSEDED', '2000-01-01T00:00:00Z']]);
        $this->deliveries([[]]);

        $process = $this->rotate(['github_app_webhook_secret'], '');

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $new = $this->stored('TF_VAR_github_app_webhook_secret');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $new);
        self::assertSame($new, json_decode((string) file_get_contents($this->dir.'/patch-body'), true)['secret'] ?? null);
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.webhook-since');
        self::assertStringNotContainsString($new, $process->getOutput().$process->getErrorOutput());
    }

    public function test_an_apply_that_changes_folder_still_finds_the_store_of_a_relative_terraform_root(): void
    {
        $this->useStore(['TF_VAR_install_token' => 'old']);
        rename($this->dir.'/store.yaml', $this->dir.'/tf/secrets.enc.yaml');
        $this->storeEnv = [];
        $repo = \dirname(__DIR__, 2);
        file_put_contents($this->dir.'/apply.sh', <<<SH
            #!/usr/bin/env bash
            cd "{$this->dir}/tf" && "{$repo}/bin/tf-secrets" exec -- sh -c 'printf %s "\${TF_VAR_install_token:-none}" > "{$this->dir}/applied"'
            SH);
        $relative = 'var/'.basename($this->dir);
        symlink($this->dir, $repo.'/'.$relative);

        try {
            $process = $this->rotate(['install_token'], '', ['ROTATE_TF_ROOT' => $relative.'/tf', 'ROTATE_TFVARS' => $relative.'/secrets.vars']);
        } finally {
            unlink($repo.'/'.$relative);
        }

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('no secret store', $process->getErrorOutput());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) file_get_contents($this->dir.'/applied'));
    }

    public function test_collect_saves_the_new_values_as_pending_and_changes_nothing_live(): void
    {
        $this->useStore(['TF_VAR_install_token' => 'old-token']);
        $before = (string) file_get_contents($this->dir.'/secrets.vars');

        $process = $this->script(['collect', 'install_token', 'do_token'], '', ['TF_SECRETS_PENDING' => $this->dir.'/pending.yaml']);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('old-token', $this->stored('TF_VAR_install_token'));
        self::assertSame($before, (string) file_get_contents($this->dir.'/secrets.vars'));
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertMatchesRegularExpression('/^install_token: "[0-9a-f]{64}"$/m', (string) file_get_contents($this->dir.'/pending.yaml'));
        self::assertStringContainsString("Collected, and not applied yet: install_token\n", $process->getOutput());
        self::assertStringContainsString('claude "Use the project-rotate-secrets skill', $process->getOutput());
    }

    public function test_a_second_collect_is_refused_until_the_pending_values_are_applied_or_discarded(): void
    {
        $this->vars("install_token = \"x\"\n");
        self::assertSame(0, $this->script(['collect', 'install_token'])->getExitCode());

        $refused = $this->script(['collect', 'install_token']);
        $discarded = $this->script(['discard']);
        $again = $this->script(['collect', 'install_token']);

        self::assertNotSame(0, $refused->getExitCode());
        self::assertStringContainsString('a rotation is pending', $refused->getErrorOutput());
        self::assertSame(0, $discarded->getExitCode(), $discarded->getErrorOutput());
        self::assertSame('x', $this->value('install_token'));
        self::assertSame(0, $again->getExitCode(), $again->getErrorOutput());
    }

    public function test_a_plan_that_changes_another_resource_applies_nothing_until_it_is_allowed(): void
    {
        $this->vars("install_token = \"x\"\n");
        $this->plan([['address' => 'module.app.digitalocean_database_cluster.db[0]', 'change' => ['actions' => ['update'], 'before' => [], 'after' => []]]]);

        $stopped = $this->rotate(['install_token'], '');

        self::assertSame(11, $stopped->getExitCode());
        self::assertStringContainsString('  module.app.digitalocean_database_cluster.db[0] update', $stopped->getErrorOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertSame([], glob($this->dir.'/.rotate-secrets.tmp.plan.*'));

        $applied = $this->script(['apply', '--allow', 'module.app.digitalocean_database_cluster.db[0]']);

        self::assertSame(0, $applied->getExitCode(), $applied->getErrorOutput());
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->value('install_token'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.tmp.*'));
    }

    public function test_the_bucket_key_replacement_passes_the_plan_check_only_when_the_key_rotates(): void
    {
        $this->vars("install_token = \"x\"\ncreate_export_bucket = true\n");
        $this->plan([
            ['address' => 'digitalocean_spaces_key.exports[0]', 'change' => ['actions' => ['delete', 'create'], 'before' => [], 'after' => []]],
            $this->appChange(['EXPORT_STORAGE_KEY' => 'old-id'], ['EXPORT_STORAGE_KEY' => null]),
        ]);

        $alone = $this->rotate(['install_token'], '');
        $this->vars("install_token = \"x\"\ncreate_export_bucket = true\n");
        unlink($this->dir.'/.rotate-secrets.pending');
        $withKey = $this->rotate(['install_token', 'export_bucket_key'], '');

        self::assertSame(11, $alone->getExitCode());
        self::assertStringContainsString("  spec.env.EXPORT_STORAGE_KEY\n  digitalocean_spaces_key.exports[0] delete,create\n", $alone->getErrorOutput());
        self::assertSame(0, $withKey->getExitCode(), $withKey->getErrorOutput());
    }

    public function test_the_app_update_passes_when_only_the_variables_of_the_rotated_secrets_change_in_any_order(): void
    {
        $this->vars("install_token = \"x\"\nmercure_jwt_secret = \"m\"\n");
        $this->plan([$this->appChange(
            ['APP_ENV' => 'prod', 'INSTALL_TOKEN' => 'old-secret-value', 'MERCURE_JWT_SECRET' => 'old-m'],
            ['MERCURE_JWT_SECRET' => 'new-m', 'INSTALL_TOKEN' => 'new-secret-value', 'APP_ENV' => 'prod'],
            mercure: ['old-m', 'new-m'],
        )]);

        $process = $this->rotate(['install_token', 'mercure_jwt_secret'], '');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertCount(1, file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES) ?: []);
    }

    public function test_an_app_field_beyond_the_rotated_secrets_stops_the_apply_and_names_the_field_with_no_value(): void
    {
        $this->vars("install_token = \"x\"\n");
        $this->plan([$this->appChange(
            ['INSTALL_TOKEN' => 'old-secret-value', 'ADMIN_EMAIL' => 'old-admin-value'],
            ['INSTALL_TOKEN' => 'new-secret-value', 'ADMIN_EMAIL' => 'new-admin-value'],
            size: ['apps-s-1vcpu-1gb', 'apps-s-2vcpu-4gb'],
        )]);

        $stopped = $this->rotate(['install_token'], '');

        self::assertSame(11, $stopped->getExitCode());
        self::assertStringContainsString("  spec.env.ADMIN_EMAIL\n  spec.service.instance_size_slug\n", $stopped->getErrorOutput());
        self::assertStringNotContainsString('INSTALL_TOKEN', $stopped->getErrorOutput());
        self::assertStringNotContainsString('-value', $stopped->getOutput().$stopped->getErrorOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');

        $field = $this->script(['apply', '--allow', 'spec.env.ADMIN_EMAIL']);
        $applied = $this->script(['apply', '--allow', 'spec.env.ADMIN_EMAIL', '--allow', 'spec.service.instance_size_slug']);

        self::assertSame(11, $field->getExitCode());
        self::assertStringNotContainsString('ADMIN_EMAIL', $field->getErrorOutput());
        self::assertSame(0, $applied->getExitCode(), $applied->getErrorOutput());
    }

    public function test_discard_is_refused_once_apply_has_started_and_apply_still_resumes(): void
    {
        $this->vars("install_token = \"x\"\n");
        $this->plan([['address' => 'module.app.digitalocean_database_cluster.db[0]', 'change' => ['actions' => ['update'], 'before' => [], 'after' => []]]]);
        $stopped = $this->rotate(['install_token'], '');

        $discarded = $this->script(['discard']);
        $collected = $this->script(['collect', 'install_token']);
        unlink($this->dir.'/plan.json');
        $applied = $this->script(['apply']);

        self::assertSame(11, $stopped->getExitCode());
        self::assertSame(1, $discarded->getExitCode());
        self::assertStringContainsString('apply has started', $discarded->getErrorOutput());
        self::assertNotSame(0, $collected->getExitCode());
        self::assertSame(0, $applied->getExitCode(), $applied->getErrorOutput());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->value('install_token'));
        self::assertFileDoesNotExist($this->dir.'/.rotate-secrets.pending');
    }

    public function test_reencrypted_does_not_skip_the_second_key_deploy_that_has_not_run(): void
    {
        $this->script(['collect', 'app_encryption_key']);

        $process = $this->script(['apply', '--reencrypted']);

        self::assertSame(10, $process->getExitCode());
        self::assertSame(['current=old previous=new', 'current=new previous=old'], file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES));
    }

    public function test_reencrypted_after_a_failed_second_key_deploy_runs_that_deploy_again_and_keeps_the_old_key(): void
    {
        file_put_contents($this->dir.'/fail-at', '2');
        $this->script(['collect', 'app_encryption_key']);
        $failed = $this->script(['apply']);

        $retried = $this->script(['apply', '--reencrypted']);
        $finished = $this->script(['apply', '--reencrypted']);

        self::assertNotSame(0, $failed->getExitCode());
        self::assertSame(10, $retried->getExitCode(), $retried->getErrorOutput());
        self::assertSame(0, $finished->getExitCode(), $finished->getErrorOutput());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old', 'current=new previous=old', 'current=new previous=none'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
    }

    public function test_an_app_field_that_the_plan_knows_only_after_the_apply_counts_as_changed(): void
    {
        $this->vars("install_token = \"x\"\n");
        $change = $this->appChange(
            ['INSTALL_TOKEN' => 'old-secret-value', 'ADMIN_EMAIL' => null],
            ['INSTALL_TOKEN' => 'new-secret-value', 'ADMIN_EMAIL' => null],
        );
        $change['change']['after_unknown'] = ['spec' => [['env' => [['value' => true], ['value' => true]], 'service' => [['instance_size_slug' => true], []]]]];
        $this->plan([$change]);

        $stopped = $this->rotate(['install_token'], '');

        self::assertSame(11, $stopped->getExitCode());
        self::assertStringContainsString("  spec.env.ADMIN_EMAIL\n  spec.service.instance_size_slug\n", $stopped->getErrorOutput());
        self::assertStringNotContainsString('INSTALL_TOKEN', $stopped->getErrorOutput());
        self::assertFileDoesNotExist($this->dir.'/applies.log');

        $change['change']['after_unknown'] = ['spec' => [['env' => [['value' => true], []]]]];
        $this->plan([$change]);
        $applied = $this->script(['apply']);

        self::assertSame(0, $applied->getExitCode(), $applied->getErrorOutput());
    }

    public function test_collect_starts_the_session_only_when_the_operator_says_yes(): void
    {
        file_put_contents($this->dir.'/session.sh', "#!/usr/bin/env bash\nprintf '%s' \"\$*\" > \"\$(dirname \"\$0\")/session-prompt\"\n");
        chmod($this->dir.'/session.sh', 0o755);
        $this->vars("install_token = \"x\"\n");

        $declined = $this->script(['collect', 'install_token'], "n\n", ['ROTATE_SESSION' => $this->dir.'/session.sh']);
        $this->script(['discard']);
        $started = $this->script(['collect', 'install_token'], "y\n", ['ROTATE_SESSION' => $this->dir.'/session.sh']);

        self::assertSame(0, $declined->getExitCode(), $declined->getErrorOutput());
        self::assertStringContainsString('Nothing was applied', $declined->getOutput());
        self::assertSame(0, $started->getExitCode(), $started->getErrorOutput());
        self::assertSame('Use the project-rotate-secrets skill to apply the pending secret rotation.', (string) file_get_contents($this->dir.'/session-prompt'));
        self::assertFileDoesNotExist($this->dir.'/applies.log');
    }

    public function test_a_new_pending_store_encrypts_to_the_recipients_of_the_live_store(): void
    {
        $this->useStore(['TF_VAR_install_token' => 'old']);
        file_put_contents($this->dir.'/store.yaml', "sops:\n    age:\n        - recipient: age1first\n          enc: x\n        - recipient: age1second\n", \FILE_APPEND);

        $process = $this->lib('live_recipients', []);

        self::assertSame('age1first,age1second', $process->getOutput());
    }

    public function test_the_script_is_valid_bash(): void
    {
        $process = new Process(['bash', '-n', \dirname(__DIR__, 2).'/bin/rotate-secrets']);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operatorScripts(): iterable
    {
        yield 'rotate-secrets' => ['bin/rotate-secrets'];
        yield 'tf-secrets' => ['bin/tf-secrets'];
        yield 'tf-secrets library' => ['bin/lib/tf-secrets.sh'];
    }

    // The tests run bash 5 in the container, and an operator on macOS runs bash 3.2.
    #[DataProvider('operatorScripts')]
    public function test_the_script_uses_no_builtin_that_macos_bash_lacks(string $path): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$path);

        self::assertDoesNotMatchRegularExpression('/\b(mapfile|readarray|coproc)\b|declare -A|local -n/', $source);
    }

    /**
     * @return array<string, string>
     */
    private function env(): array
    {
        return [
            'ROTATE_TFVARS' => $this->dir.'/secrets.vars',
            'ROTATE_TF_ROOT' => $this->dir.'/tf',
            'ROTATE_APPLY' => $this->dir.'/apply.sh',
            'ROTATE_PLAN' => $this->dir.'/plan.sh',
            'ROTATE_PLAN_SHOW' => $this->dir.'/plan-show.sh',
            'ROTATE_SESSION' => '',
            'ROTATE_APP_ID' => 'app-1',
            'ROTATE_VAPID_CMD' => $this->dir.'/vapid.sh',
            'ROTATE_GITHUB_API' => 'https://api.github.test',
            'ROTATE_POLL_SECONDS' => '0.1',
            'ROTATE_POLL_TIMEOUT' => '30',
            'ROTATE_RESEND_SECONDS' => '0.1',
            'ROTATE_RESEND_TIMEOUT' => '30',
            'PATH' => $this->dir.'/bin:'.getenv('PATH'),
        ] + $this->storeEnv;
    }

    /**
     * Sets up a secret store of the fake sops, holding the given values by environment name.
     *
     * @param array<string, string> $values
     */
    private function useStore(array $values): void
    {
        copy(__DIR__.'/fixtures/fake-sops', $this->dir.'/bin/sops');
        chmod($this->dir.'/bin/sops', 0o755);
        copy(\dirname(__DIR__, 2).'/terraform/secrets.list', $this->dir.'/tf/secrets.list');
        $lines = '';
        foreach ($values as $name => $value) {
            $lines .= $name.': '.json_encode($value, \JSON_THROW_ON_ERROR)."\n";
        }
        file_put_contents($this->dir.'/store.yaml', $lines);
        $this->storeEnv = ['TF_SECRETS_STORE' => $this->dir.'/store.yaml'];
    }

    private function stored(string $name): string
    {
        $process = new Process(
            ['bash', '-c', 'sops decrypt --output-type json "$F" | jq -j --arg n "$N" ".[\$n] // empty"'],
            env: ['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'F' => $this->dir.'/store.yaml', 'N' => $name],
        );
        $process->mustRun();

        return $process->getOutput();
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
     * Collects, then applies, then applies again with --reencrypted when the key rotation asks for it.
     *
     * @param list<string>          $names
     * @param array<string, string> $extraEnv
     */
    private function rotate(array $names, string $input, array $extraEnv = [], bool $reencrypted = true): Process
    {
        $process = new Process(
            ['bash', '-c', '"$0" collect "$@" || exit; "$0" apply; s=$?; [ "$s" = 10 ] && [ "$REENCRYPTED" = 1 ] && { "$0" apply --reencrypted; s=$?; }; exit "$s"', \dirname(__DIR__, 2).'/bin/rotate-secrets', ...$names],
            env: ['REENCRYPTED' => $reencrypted ? '1' : '0'] + $extraEnv + $this->env(),
            input: $input,
        );
        $process->run();

        return $process;
    }

    /**
     * Runs apply again, as the session does after a stop, with --reencrypted when the key rotation asks for it.
     *
     * @param array<string, string> $extraEnv
     */
    private function resume(array $extraEnv = []): Process
    {
        $process = new Process(
            ['bash', '-c', '"$0" apply; s=$?; [ "$s" = 10 ] && { "$0" apply --reencrypted; s=$?; }; exit "$s"', \dirname(__DIR__, 2).'/bin/rotate-secrets'],
            env: $extraEnv + $this->env(),
        );
        $process->run();

        return $process;
    }

    /**
     * Runs one mode of the script, such as apply or discard.
     *
     * @param list<string>          $args
     * @param array<string, string> $extraEnv
     */
    private function script(array $args, string $input = '', array $extraEnv = []): Process
    {
        $process = new Process([\dirname(__DIR__, 2).'/bin/rotate-secrets', ...$args], env: $extraEnv + $this->env(), input: $input);
        $process->run();

        return $process;
    }

    private function writeStubs(): void
    {
        mkdir($this->dir.'/bin');
        $stubs = [
            'doctl' => <<<'SH'
                #!/usr/bin/env bash
                d="$(cd "$(dirname "$0")/.." && pwd)"
                echo "$*" >> "$d/doctl.log"
                echo "doctl $1 $2" >> "$d/events.log"
                case "$1 $2" in
                    "apps get")
                        f="$d/active"
                        head -n 1 "$f"
                        if [ "$(wc -l < "$f")" -gt 1 ]; then tail -n +2 "$f" > "$f.tmp"; mv "$f.tmp" "$f"; fi ;;
                    "apps list-deployments") cat "$d/deployments.json" ;;
                    *) exit 9 ;;
                esac
                SH,
            'curl' => <<<'SH'
                #!/usr/bin/env bash
                d="$(cd "$(dirname "$0")/.." && pwd)"
                args=("$@"); method=GET; url=
                for ((i = 0; i < ${#args[@]}; i++)); do
                    case ${args[i]} in
                        -X) method=${args[++i]} ;;
                        -H) h=${args[++i]}; [[ $h != @* ]] || h=$(cat "${h#@}"); [[ $h != Authorization:* ]] || printf '%s' "$h" > "$d/auth-header" ;;
                        --data-binary) f=${args[++i]}; cat "${f#@}" > "$d/patch-body" ;;
                        http*) url=${args[i]} ;;
                    esac
                done
                echo "curl $method ${url%%\?*}" >> "$d/events.log"
                case "$method $url" in
                    "PATCH "*/app/hook/config) [ ! -f "$d/patch-fail" ] || exit 22; printf 'HTTP/2 200\r\n\r\n{}' ;;
                    "GET "*/app/hook/deliveries*)
                        f="$d/deliveries"
                        body=$(head -n 1 "$f")
                        if [ "$(wc -l < "$f")" -gt 1 ]; then tail -n +2 "$f" > "$f.tmp"; mv "$f.tmp" "$f"; fi
                        printf 'HTTP/2 200\r\ncontent-type: application/json\r\n\r\n%s' "$body" ;;
                    "POST "*/attempts) echo "$url" >> "$d/attempts.log"; printf 'HTTP/2 202\r\n\r\n{}' ;;
                    *) exit 9 ;;
                esac
                SH,
        ];
        foreach ($stubs as $name => $body) {
            file_put_contents($this->dir.'/bin/'.$name, $body."\n");
            chmod($this->dir.'/bin/'.$name, 0o755);
        }
        file_put_contents($this->dir.'/vapid.sh', "#!/usr/bin/env bash\necho VAPID_PUBLIC_KEY=new-vapid-public\necho VAPID_PRIVATE_KEY=new-vapid-private\n");
        chmod($this->dir.'/vapid.sh', 0o755);
    }

    /**
     * Rewrites the fixture file with the required lines, the old key and the given lines.
     */
    private function vars(string $extra): void
    {
        $required = str_contains($extra, 'mailer_dsn =') ? str_replace("mailer_dsn = \"null://null\"\n", '', self::REQUIRED) : self::REQUIRED;
        file_put_contents($this->dir.'/secrets.vars', $required."app_secret = \"keep-me\"\napp_encryption_key = \"".self::OLD_KEY."\"\n".$extra);
    }

    /**
     * Sets what the stub plan changes, as the resource_changes of terraform show -json.
     *
     * @param list<array<string, mixed>> $changes
     */
    private function plan(array $changes): void
    {
        file_put_contents($this->dir.'/plan.json', json_encode(['resource_changes' => $changes], \JSON_THROW_ON_ERROR));
    }

    /**
     * An update of the app whose spec holds the given app variables, the web size and the hub key.
     *
     * @param array<string, ?string>      $before
     * @param array<string, ?string>      $after
     * @param array{0: string, 1: string} $size
     * @param array{0: string, 1: string} $mercure
     *
     * @return array<string, mixed>
     */
    private function appChange(array $before, array $after, array $size = ['apps-s-1vcpu-1gb', 'apps-s-1vcpu-1gb'], array $mercure = ['m', 'm']): array
    {
        $spec = static fn (array $env, string $size, string $hubKey): array => [[
            'name' => 'loupe',
            'env' => array_map(static fn (string $k, ?string $v): array => ['key' => $k, 'value' => $v, 'type' => 'SECRET', 'scope' => 'RUN_TIME'], array_keys($env), $env),
            'service' => [
                ['name' => 'web', 'instance_size_slug' => $size, 'env' => []],
                ['name' => 'mercure', 'instance_size_slug' => 'apps-s-1vcpu-0.5gb', 'env' => [
                    ['key' => 'MERCURE_PUBLISHER_JWT_KEY', 'value' => $hubKey, 'type' => 'SECRET', 'scope' => 'RUN_TIME'],
                ]],
            ],
        ]];

        return ['address' => 'module.app.digitalocean_app.app', 'change' => [
            'actions' => ['update'],
            'before' => ['id' => 'app-1', 'spec' => $spec($before, $size[0], $mercure[0])],
            'after' => ['spec' => $spec($after, $size[1], $mercure[1])],
        ]];
    }

    private function webhookVars(): void
    {
        $this->vars("github_app_webhook_secret = \"old-webhook-secret\"\ngithub_app_id = \"12345\"\ngithub_app_private_key = ".$this->hcl($this->pem())."\n");
    }

    private function hcl(string $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private function value(string $name): string
    {
        return (string) base64_decode($this->readWithTerraform($name), true);
    }

    private function pem(?string $passphrase = null): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem, $passphrase);

        return $pem;
    }

    /**
     * @param list<string>                        $active      what `doctl apps get` answers, one per call, the last one repeating
     * @param list<array{string, string, string}> $deployments id, phase and creation time
     */
    private function deployments(array $active, array $deployments): void
    {
        file_put_contents($this->dir.'/active', implode("\n", $active)."\n");
        file_put_contents($this->dir.'/deployments.json', json_encode(array_map(
            static fn (array $d): array => ['id' => $d[0], 'phase' => $d[1], 'created_at' => $d[2]],
            $deployments,
        )));
    }

    /**
     * @param list<list<array<string, mixed>>> $lists what each call of the deliveries list answers, the last one repeating
     */
    private function deliveries(array $lists): void
    {
        file_put_contents($this->dir.'/deliveries', implode("\n", array_map(static fn (array $l): string => json_encode($l, \JSON_THROW_ON_ERROR), $lists))."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function delivery(int $id, string $guid, int $code, string $status, string $at = '2099-01-01T00:00:00Z'): array
    {
        return ['id' => $id, 'guid' => $guid, 'delivered_at' => $at, 'redelivery' => false, 'status' => $status, 'status_code' => $code];
    }

    private function assertValidAppJwt(): void
    {
        $header = (string) file_get_contents($this->dir.'/auth-header');
        if (1 !== preg_match('/^Authorization: Bearer ([\w-]+)\.([\w-]+)\.([\w-]+)$/', $header, $m)) {
            self::fail('The header must hold a three part token.');
        }
        $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($m[1]), true));
        $claims = json_decode($decode($m[2]), true);
        self::assertSame('12345', (string) ($claims['iss'] ?? ''));
        self::assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
        $private = openssl_pkey_get_private($this->value('github_app_private_key'));
        self::assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        self::assertIsArray($details);
        self::assertSame(1, openssl_verify($m[1].'.'.$m[2], $decode($m[3]), $details['key'], \OPENSSL_ALGO_SHA256));
    }
}
