<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RotateSecretsTest extends TestCase
{
    private const string OLD_KEY = 'T0xELEtFWS1PTEQtS0VZLU9MRC1LRVktT0xELUtFWS1PTA==';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/rotate-secrets-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/terraform.tfvars', "app_secret = \"keep-me\"\napp_encryption_key = \"".self::OLD_KEY."\"\n");
        // The stub apply records what each deploy would carry, as yes/no, never as a value.
        file_put_contents($this->dir.'/apply.sh', <<<'SH'
            #!/usr/bin/env bash
            f="$(dirname "$0")/terraform.tfvars"
            cur=$(awk -F'"' '/^app_encryption_key[ \t]*=/ {print $2}' "$f")
            prev=$(awk -F'"' '/^app_encryption_key_previous[ \t]*=/ {print $2}' "$f")
            old=T0xELEtFWS1PTEQtS0VZLU9MRC1LRVktT0xELUtFWS1PTA==
            label() { if [ -z "$1" ]; then echo none; elif [ "$1" = "$old" ]; then echo old; else echo new; fi; }
            echo "current=$(label "$cur") previous=$(label "$prev")" >> "$(dirname "$0")/applies.log"
            SH);
        chmod($this->dir.'/apply.sh', 0o755);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function test_it_rotates_the_key_in_three_deploys_and_never_prints_a_key(): void
    {
        $process = $this->rotate(['app_encryption_key'], "yes\n");

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame(
            ['current=old previous=new', 'current=new previous=old', 'current=new previous=none'],
            file($this->dir.'/applies.log', \FILE_IGNORE_NEW_LINES),
        );
        $tfvars = (string) file_get_contents($this->dir.'/terraform.tfvars');
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
        self::assertStringContainsString('app_encryption_key_previous = "'.self::OLD_KEY.'"', (string) file_get_contents($this->dir.'/terraform.tfvars'));
        self::assertStringNotContainsString(self::OLD_KEY, $process->getOutput().$process->getErrorOutput());
    }

    public function test_it_refuses_an_unknown_secret_before_any_apply(): void
    {
        $process = $this->rotate(['no_such_secret'], '');

        self::assertSame(1, $process->getExitCode());
        self::assertFileDoesNotExist($this->dir.'/applies.log');
        self::assertStringContainsString("unknown secret 'no_such_secret'", $process->getErrorOutput());
    }

    /**
     * @param list<string> $names
     */
    private function rotate(array $names, string $input): Process
    {
        $process = new Process(
            [\dirname(__DIR__, 2).'/bin/rotate-secrets', ...$names],
            env: ['ROTATE_TFVARS' => $this->dir.'/terraform.tfvars', 'ROTATE_APPLY' => $this->dir.'/apply.sh'],
            input: $input,
        );
        $process->run();

        return $process;
    }
}
