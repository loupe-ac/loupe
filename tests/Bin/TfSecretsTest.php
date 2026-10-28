<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class TfSecretsTest extends TestCase
{
    private const string REQUIRED = "app_encryption_key = \"a2V5\"\nmailer_dsn = \"null://null\"\n";

    private string $dir;

    private string $root;

    protected function setUp(): void
    {
        $this->root = \dirname(__DIR__, 2);
        $this->dir = sys_get_temp_dir().'/tf-secrets-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/tf', recursive: true);
        mkdir($this->dir.'/bin');
        copy($this->root.'/terraform/variables.tf', $this->dir.'/tf/variables.tf');
        copy($this->root.'/terraform/secrets.list', $this->dir.'/tf/secrets.list');
        copy(__DIR__.'/fixtures/fake-sops', $this->dir.'/bin/sops');
        chmod($this->dir.'/bin/sops', 0o755);
        touch($this->dir.'/store.yaml');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function test_every_sensitive_variable_but_the_sentry_dsn_is_listed(): void
    {
        $variables = (string) file_get_contents($this->root.'/terraform/variables.tf');
        preg_match_all('/^variable "([a-z_0-9]+)" \{(.*?)^\}/ms', $variables, $matches, \PREG_SET_ORDER);
        $declared = $sensitive = [];
        foreach ($matches as $match) {
            $declared[] = $match[1];
            if (str_contains($match[2], 'sensitive   = true') && 'sentry_dsn' !== $match[1]) {
                $sensitive[] = $match[1];
            }
        }
        $listed = array_map(
            static fn (string $line): string => explode(' ', $line)[0],
            array_values(array_filter(
                explode("\n", (string) file_get_contents($this->root.'/terraform/secrets.list')),
                static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
            )),
        );

        self::assertSame([], array_values(array_diff($sensitive, $listed)), 'A sensitive variable has no line in secrets.list.');
        self::assertSame([], array_values(array_diff($listed, $declared)), 'secrets.list names a variable that variables.tf does not declare.');
    }

    public function test_import_copies_exact_values_and_verify_reads_same(): void
    {
        $this->tfvars(<<<'HCL'
            app_secret = "q\"uote\\n$${tpl}"
            oauth_private_key = <<EOT
            -----BEGIN KEY-----
            line "two" \n
            EOT
            HCL);

        $import = $this->tf(['import']);
        self::assertSame(0, $import->getExitCode(), $import->getErrorOutput());
        self::assertStringContainsString('TF_VAR_app_secret copied', $import->getOutput());
        self::assertStringContainsString('TF_VAR_oauth_private_key copied', $import->getOutput());
        self::assertStringContainsString('DIGITALOCEAN_TOKEN unset', $import->getOutput());
        self::assertSame('q"uote\n${tpl}', $this->stored('TF_VAR_app_secret'));
        self::assertSame("-----BEGIN KEY-----\nline \"two\" \\n\n", $this->stored('TF_VAR_oauth_private_key'));

        $verify = $this->tf(['verify']);
        self::assertSame(0, $verify->getExitCode(), $verify->getErrorOutput());
        self::assertStringContainsString('TF_VAR_app_secret same', $verify->getOutput());
        self::assertStringContainsString('DIGITALOCEAN_TOKEN unset', $verify->getOutput());
        self::assertStringNotContainsString('uote', $import->getOutput().$verify->getOutput().$verify->getErrorOutput());
    }

    public function test_a_provider_input_that_tfvars_leaves_null_takes_the_environment_value(): void
    {
        $this->tfvars('app_secret = "x"');

        $import = $this->tf(['import'], ['DIGITALOCEAN_TOKEN' => 'do-token-value']);
        self::assertSame(0, $import->getExitCode(), $import->getErrorOutput());
        self::assertStringContainsString('DIGITALOCEAN_TOKEN copied', $import->getOutput());
        self::assertSame('do-token-value', $this->stored('DIGITALOCEAN_TOKEN'));

        $same = $this->tf(['verify'], ['DIGITALOCEAN_TOKEN' => 'do-token-value']);
        self::assertStringContainsString('DIGITALOCEAN_TOKEN same', $same->getOutput());

        $differs = $this->tf(['verify'], ['DIGITALOCEAN_TOKEN' => 'other']);
        self::assertSame(1, $differs->getExitCode());
        self::assertStringContainsString('DIGITALOCEAN_TOKEN differs', $differs->getOutput());
        self::assertStringNotContainsString('other', $differs->getOutput());
    }

    public function test_verify_reports_a_stored_value_that_differs(): void
    {
        $this->tfvars('app_secret = "one"');
        $this->tf(['import']);
        $this->tf(['set', 'TF_VAR_app_secret'], input: 'two');

        $verify = $this->tf(['verify']);

        self::assertSame(1, $verify->getExitCode());
        self::assertStringContainsString('TF_VAR_app_secret differs', $verify->getOutput());
    }

    public function test_import_stops_and_names_the_input_when_terraform_cannot_read_it(): void
    {
        $this->tfvars('app_secret = ');

        $import = $this->tf(['import']);

        self::assertNotSame(0, $import->getExitCode());
        self::assertStringContainsString('could not read', $import->getErrorOutput());
        self::assertStringNotContainsString('app_secret = ', $import->getOutput().$import->getErrorOutput());
    }

    public function test_set_keeps_the_value_out_of_every_argument_and_the_output(): void
    {
        $this->tfvars('app_secret = "x"');
        $log = $this->dir.'/sops.log';

        $set = $this->tf(['set', 'TF_VAR_app_secret'], ['FAKE_SOPS_LOG' => $log], "l1\nl2 \"q\"");

        self::assertSame(0, $set->getExitCode(), $set->getErrorOutput());
        self::assertSame("l1\nl2 \"q\"", $this->stored('TF_VAR_app_secret'));
        self::assertStringNotContainsString('l1', (string) file_get_contents($log));
        self::assertStringNotContainsString('l1', $set->getOutput().$set->getErrorOutput());
        $names = $this->tf(['names']);
        self::assertSame("TF_VAR_app_secret\n", $names->getOutput());
    }

    public function test_set_gives_sops_a_yaml_temporary_file_and_removes_it(): void
    {
        $this->tfvars('app_secret = "x"');

        $set = $this->tf(['set', 'TF_VAR_app_secret'], [], 'v1');

        self::assertSame(0, $set->getExitCode(), $set->getErrorOutput());
        self::assertSame('v1', $this->stored('TF_VAR_app_secret'));
        self::assertSame([], glob($this->dir.'/.rotate-secrets.*'));
    }

    public function test_a_store_file_whose_name_does_not_end_in_yaml_is_refused(): void
    {
        rename($this->dir.'/store.yaml', $this->dir.'/store.enc');

        $exec = $this->tf(['exec', '--', 'true'], ['TF_SECRETS_STORE' => $this->dir.'/store.enc']);

        self::assertSame(1, $exec->getExitCode());
        self::assertStringContainsString('must end in .yaml', $exec->getErrorOutput());
    }

    public function test_set_refuses_a_name_that_is_not_listed(): void
    {
        $set = $this->tf(['set', 'NOT_LISTED'], input: 'v');

        self::assertNotSame(0, $set->getExitCode());
        self::assertStringContainsString('not in secrets.list', $set->getErrorOutput());
    }

    public function test_exec_puts_the_values_in_the_environment_of_the_command_only(): void
    {
        $this->tf(['set', 'TF_VAR_app_secret'], input: 'the value');
        $log = $this->dir.'/sops.log';

        $exec = $this->tf(['exec', '--', 'sh', '-c', 'printf %s "$TF_VAR_app_secret"'], ['FAKE_SOPS_LOG' => $log]);

        self::assertSame('the value', $exec->getOutput());
        self::assertStringNotContainsString('the value', (string) file_get_contents($log));
    }

    public function test_exec_runs_the_command_unchanged_when_no_store_is_set_up(): void
    {
        unlink($this->dir.'/store.yaml');

        $exec = $this->tf(['exec', '--', 'sh', '-c', 'printf %s "${TF_VAR_app_secret:-none}"'], ['TF_VAR_app_secret' => 'plain']);

        self::assertSame('plain', $exec->getOutput());
        self::assertStringContainsString('no secret store', $exec->getErrorOutput());
    }

    private function tfvars(string $extra): void
    {
        file_put_contents($this->dir.'/tf/terraform.tfvars', self::REQUIRED.$extra."\n");
    }

    private function stored(string $name): string
    {
        $process = new Process(
            ['bash', '-c', 'sops decrypt --output-type json "$F" | jq -j --arg n "$N" ".[\$n]"'],
            env: ['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'F' => $this->dir.'/store.yaml', 'N' => $name],
        );
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function tf(array $args, array $env = [], string $input = ''): Process
    {
        $process = new Process(
            [$this->root.'/bin/tf-secrets', ...$args],
            cwd: $this->root,
            env: $env + [
                'PATH' => $this->dir.'/bin:'.getenv('PATH'),
                'TF_SECRETS_ROOT' => $this->dir.'/tf',
                'TF_SECRETS_TFVARS' => $this->dir.'/tf/terraform.tfvars',
                'TF_SECRETS_LIST' => $this->dir.'/tf/secrets.list',
                'TF_SECRETS_STORE' => $this->dir.'/store.yaml',
                'DIGITALOCEAN_TOKEN' => '',
                'DIGITALOCEAN_ACCESS_TOKEN' => '',
                'SPACES_ACCESS_KEY_ID' => '',
                'SPACES_SECRET_ACCESS_KEY' => '',
            ],
            input: $input,
        );
        $process->run();

        return $process;
    }
}
