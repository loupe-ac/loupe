<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub;

use App\Module\GitHub\GitHubRemoteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubRemoteUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'https' => ['https://github.com/loupe-ac/loupe', 'loupe-ac/loupe'];
        yield 'https with .git' => ['https://github.com/loupe-ac/loupe.git', 'loupe-ac/loupe'];
        yield 'ssh' => ['git@github.com:loupe-ac/loupe', 'loupe-ac/loupe'];
        yield 'ssh with .git' => ['git@github.com:loupe-ac/loupe.git', 'loupe-ac/loupe'];
        yield 'a dotted repository name' => ['https://github.com/Loupe-AC/Loupe.site.git', 'Loupe-AC/Loupe.site'];
        yield 'a repository name with a dot git inside' => ['https://github.com/acme/a.git.git', 'acme/a.git'];
    }

    #[DataProvider('accepted')]
    public function test_it_reads_the_path_of_a_github_remote(string $url, string $path): void
    {
        self::assertSame($path, GitHubRemoteUrl::pathOf($url));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'an empty url' => [''];
        yield 'http' => ['http://github.com/loupe-ac/loupe'];
        yield 'another host' => ['https://gitlab.com/loupe-ac/loupe'];
        yield 'a lookalike host' => ['https://github.com.evil.test/loupe-ac/loupe'];
        yield 'a host with a user' => ['https://token@github.com/loupe-ac/loupe'];
        yield 'an ssh url with a scheme' => ['ssh://git@github.com/loupe-ac/loupe.git'];
        yield 'a missing repository' => ['https://github.com/loupe-ac'];
        yield 'a trailing slash' => ['https://github.com/loupe-ac/loupe/'];
        yield 'a deeper path' => ['https://github.com/loupe-ac/loupe/tree/main'];
        yield 'a bare .git repository' => ['https://github.com/loupe-ac/.git'];
        yield 'a dot repository' => ['https://github.com/loupe-ac/.'];
        yield 'a dot dot repository' => ['https://github.com/loupe-ac/..'];
        yield 'an owner with a leading dash' => ['https://github.com/-loupe/loupe'];
        yield 'a query string' => ['https://github.com/loupe-ac/loupe?x=1'];
        yield 'a trailing newline' => ["https://github.com/loupe-ac/loupe\n"];
    }

    #[DataProvider('refused')]
    public function test_it_refuses_any_other_shape(string $url): void
    {
        self::assertNull(GitHubRemoteUrl::pathOf($url));
    }
}
