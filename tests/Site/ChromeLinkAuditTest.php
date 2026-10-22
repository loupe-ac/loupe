<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Site\ChromeLinkAudit;
use PHPUnit\Framework\TestCase;

final class ChromeLinkAuditTest extends TestCase
{
    public function test_it_flags_localhost_and_an_unknown_host(): void
    {
        $html = '<a href="http://localhost/login">a</a><a href="https://other.example/">b</a><a href="https://site.example/">c</a>';

        self::assertCount(2, ChromeLinkAudit::problems($html, ['site.example']));
    }

    public function test_it_accepts_a_host_the_caller_configures(): void
    {
        self::assertSame([], ChromeLinkAudit::problems('<a href="https://x.com/loupehq">x</a>', ['site.example', 'X.com']));
    }

    public function test_it_no_longer_accepts_x_without_configuration(): void
    {
        self::assertCount(1, ChromeLinkAudit::problems('<a href="https://x.com/loupehq">x</a>', ['site.example']));
    }

    public function test_a_root_path_passes_only_when_the_caller_allows_it(): void
    {
        $html = '<a href="/blog">b</a><a href="//evil.example/">e</a>';

        self::assertCount(2, ChromeLinkAudit::problems($html, ['site.example']));
        self::assertCount(1, ChromeLinkAudit::problems($html, ['site.example'], true));
    }
}
