<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\AboutPage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The shipped about file parses and follows the rules of the page: one builder, no numbers, the Loupe links and the difference section. */
final class AboutContentTest extends KernelTestCase
{
    public function test_the_file_parses_with_a_title_and_a_description(): void
    {
        $page = $this->about()->page();

        self::assertNotSame('', $page->title);
        self::assertNotSame('', $page->description);
        self::assertStringContainsString('Geoffrey Bachelet', $page->html);
    }

    public function test_the_page_speaks_in_the_first_person_and_never_says_we(): void
    {
        $text = strip_tags($this->about()->page()->html);

        self::assertMatchesRegularExpression('/\bI\b/', $text);
        self::assertDoesNotMatchRegularExpression('/\bwe\b/i', $text);
    }

    public function test_the_page_shows_no_number_outside_a_link(): void
    {
        $html = (string) preg_replace('#<a [^>]*>.*?</a>#s', '', $this->about()->page()->html);
        $html = (string) preg_replace('#</?(ol|li)>#', '', $html);

        self::assertDoesNotMatchRegularExpression('/\d/', strip_tags($html));
    }

    public function test_the_page_names_how_loupe_is_different(): void
    {
        $html = $this->about()->page()->html;

        self::assertStringContainsString('<h2>How Loupe is different</h2>', $html);
        self::assertStringContainsString('workflow based', $html);
        self::assertStringContainsString('more than start agents', $html);
        self::assertStringContainsString('strong analytics', $html);
    }

    public function test_the_page_links_the_loupe_repository_the_loupe_x_account_and_the_discord_server(): void
    {
        $html = $this->about()->page()->html;

        self::assertStringContainsString('href="https://github.com/loupe-ac/loupe"', $html);
        self::assertStringContainsString('href="https://x.com/LoupeHQ"', $html);
        self::assertStringNotContainsString('ubermuda', $html);
        self::assertMatchesRegularExpression('#href="https://discord\.gg/[A-Za-z0-9]+"#', $html);
    }

    private function about(): AboutPage
    {
        $about = static::getContainer()->get(AboutPage::class);
        self::assertInstanceOf(AboutPage::class, $about);

        return $about;
    }
}
