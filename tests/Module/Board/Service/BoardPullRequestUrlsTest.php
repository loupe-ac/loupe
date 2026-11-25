<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Service\BoardPullRequestUrls;
use App\Module\Board\Service\GitHubPullRequestUrlParser;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Workflow\Contract\PullRequestKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardPullRequestUrlsTest extends TestCase
{
    private BoardPullRequestUrls $urls;

    protected function setUp(): void
    {
        $this->urls = new BoardPullRequestUrls(new PullRequestUrlResolver([new GitHubPullRequestUrlParser()]));
    }

    public function test_a_github_url_gives_the_forge_slug_the_lower_case_repository_and_the_number(): void
    {
        self::assertEquals(new PullRequestKey('github', 'acme/widgets', 12), $this->urls->keyOf('https://github.com/Acme/Widgets/pull/12'));
    }

    public function test_a_url_with_spaces_around_it_is_read(): void
    {
        self::assertEquals(new PullRequestKey('github', 'acme/widgets', 12), $this->urls->keyOf("  https://github.com/acme/widgets/pull/12\n"));
    }

    /** @return iterable<string, array{string}> */
    public static function urlsWithNoKey(): iterable
    {
        yield 'an unknown host' => ['https://code.example.org/team/app/pulls/12'];
        yield 'a github issue' => ['https://github.com/acme/widgets/issues/12'];
        yield 'a repository root' => ['https://github.com/acme/widgets'];
        yield 'text' => ['not a url'];
        yield 'empty' => [''];
    }

    #[DataProvider('urlsWithNoKey')]
    public function test_a_url_that_names_no_pull_request_gives_null(string $url): void
    {
        self::assertNull($this->urls->keyOf($url));
    }
}
