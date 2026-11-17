<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ShowInstallScriptCommand;
use App\Module\Bridge\Command\ShowInstallScriptHandler;
use PHPUnit\Framework\TestCase;

final class ShowInstallScriptHandlerTest extends TestCase
{
    private const string ACTIVE_KEY = 'loupe-release ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAUeC5MaWHXeMaiqKi9xzbbVIBvclqrpBTYr8OdIQUOs';
    private const string ACTIVE_FINGERPRINT = 'SHA256:mPVVTkHngFt3LMCmwB7Nw6BtoS371HG9RRKbhn6T4jM';
    private const string BACKUP_KEY = 'loupe-release ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ45ZR/m+TS0VjF4rHIbtWD5JcIcbqhW4TegaR0CuWZb';

    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'install-script-') ?: throw new \RuntimeException('No temporary file.');
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_it_sets_the_instance_and_the_major_after_the_first_line(): void
    {
        file_put_contents($this->path, "#!/bin/sh\nset -eu\necho hi\n");

        $script = new ShowInstallScriptHandler($this->path)(new ShowInstallScriptCommand('https://loupe.example.com'));

        self::assertSame("#!/bin/sh\nLOUPE_URL='https://loupe.example.com'\nLOUPE_CLI_MAJOR='2'\nset -eu\necho hi\n", $script);
    }

    public function test_a_quote_in_the_url_cannot_end_the_string(): void
    {
        file_put_contents($this->path, "#!/bin/sh\necho hi\n");

        $script = new ShowInstallScriptHandler($this->path)(new ShowInstallScriptCommand("https://x.example/a'b"));

        self::assertStringContainsString("\nLOUPE_URL='https://x.example/a'\\''b'\n", $script);
    }

    public function test_it_removes_the_key_lines_whose_fingerprint_is_revoked(): void
    {
        file_put_contents($this->path, self::scriptWithKeys());

        $script = new ShowInstallScriptHandler($this->path, [self::ACTIVE_FINGERPRINT])(new ShowInstallScriptCommand('https://loupe.example.com'));

        self::assertStringNotContainsString(self::ACTIVE_KEY, $script);
        self::assertStringContainsString(self::BACKUP_KEY, $script);
        self::assertStringContainsString("# BEGIN release_keys\n", $script);
        self::assertStringContainsString("# END release_keys\necho done\n", $script);
    }

    public function test_an_empty_revoked_list_keeps_every_key_line(): void
    {
        file_put_contents($this->path, self::scriptWithKeys());

        $script = new ShowInstallScriptHandler($this->path, [])(new ShowInstallScriptCommand('https://loupe.example.com'));

        self::assertStringContainsString(self::ACTIVE_KEY, $script);
        self::assertStringContainsString(self::BACKUP_KEY, $script);
    }

    public function test_a_revoked_fingerprint_removes_a_key_line_only_between_the_markers(): void
    {
        file_put_contents($this->path, "#!/bin/sh\n".self::ACTIVE_KEY."\n# BEGIN release_keys\n# END release_keys\n");

        $script = new ShowInstallScriptHandler($this->path, [self::ACTIVE_FINGERPRINT])(new ShowInstallScriptCommand('https://loupe.example.com'));

        self::assertStringContainsString("\n".self::ACTIVE_KEY."\n", $script);
    }

    public function test_a_line_that_is_not_a_key_stays_between_the_markers(): void
    {
        file_put_contents($this->path, "#!/bin/sh\n# BEGIN release_keys\nloupe-release ssh-ed25519 !!not-base64!!\n# comment\n# END release_keys\n");

        $script = new ShowInstallScriptHandler($this->path, [self::ACTIVE_FINGERPRINT])(new ShowInstallScriptCommand('https://loupe.example.com'));

        self::assertStringContainsString("loupe-release ssh-ed25519 !!not-base64!!\n# comment\n", $script);
    }

    private static function scriptWithKeys(): string
    {
        return "#!/bin/sh\nset -eu\n# BEGIN release_keys\n".self::ACTIVE_KEY."\n".self::BACKUP_KEY."\n# END release_keys\necho done\n";
    }

    public function test_a_missing_script_fails_loudly(): void
    {
        unlink($this->path);

        $this->expectException(\RuntimeException::class);

        new ShowInstallScriptHandler($this->path)(new ShowInstallScriptCommand('https://loupe.example.com'));
    }
}
