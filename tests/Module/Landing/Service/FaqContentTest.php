<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\FaqPage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The shipped FAQ file parses and follows the rules for sources, open answers and counted figures. */
final class FaqContentTest extends KernelTestCase
{
    private const string FILE = __DIR__.'/../../../../src/Module/Landing/Resources/faq.md';

    public function test_the_file_parses_with_a_title_a_description_and_questions(): void
    {
        $faq = $this->faq()->content();

        self::assertNotSame('', $faq->title);
        self::assertNotSame('', $faq->description);
        self::assertNotEmpty($faq->questions);
        self::assertLessThanOrEqual(new \DateTimeImmutable('tomorrow'), $faq->updatedOn);
        foreach ($faq->questions as $question) {
            self::assertStringContainsString('id="'.$question->anchor.'"', $faq->html);
        }
    }

    public function test_no_url_is_shown_as_bare_text(): void
    {
        self::assertStringNotContainsString('http', strip_tags($this->faq()->content()->html));
    }

    public function test_every_citation_is_a_link_to_a_listed_source_from_a_permitted_kind_of_site(): void
    {
        $html = $this->faq()->content()->html;
        [$body, $sources] = explode('>Sources</h2>', $html, 2);

        preg_match_all('/\[(\d+)\]/', strip_tags(preg_replace('#<a [^>]*>#', '', $body) ?? ''), $cited);
        $listed = preg_match_all('/<li id="source-\d+">/', $sources);
        foreach ($cited[1] as $number) {
            self::assertLessThanOrEqual($listed, (int) $number, 'The FAQ cites ['.$number.'], which is not in its sources.');
        }
        $unlinked = preg_replace('#<a [^>]*href="\#source-(\d+)">\[\1\]</a>#', '', $html);
        self::assertDoesNotMatchRegularExpression('/\[\d+\]/', strip_tags((string) $unlinked), 'A citation links nowhere.');
        self::assertDoesNotMatchRegularExpression(
            '#href="https?://([a-z.]+\.)?(reddit\.com|news\.ycombinator\.com|x\.com|twitter\.com)#',
            $sources,
            'The FAQ cites a discussion site.',
        );
        preg_match_all('#<li id="(source-\d+)">(.*?)</li>#s', $sources, $items, \PREG_SET_ORDER);
        self::assertNotEmpty($items);
        foreach ($items as [, $id, $item]) {
            self::assertStringContainsString('<a ', $item, $id.' has no link.');
        }
    }

    public function test_every_question_cites_a_source_or_carries_an_open_paragraph(): void
    {
        $source = $this->source();
        $sections = preg_split('/^### /m', explode("\n## How we counted", $source, 2)[0]);
        self::assertIsArray($sections);
        array_shift($sections);
        self::assertNotEmpty($sections);

        foreach ($sections as $section) {
            self::assertTrue(
                1 === preg_match('/\[\d+\]/', $section) || str_contains($section, '**Open.**'),
                'A question has no citation and no Open paragraph: '.strtok($section, "\n"),
            );
        }
    }

    public function test_every_open_paragraph_names_the_work_that_answers_it(): void
    {
        preg_match_all('/^\*\*Open\.\*\* (.+)$/m', $this->source(), $open);

        self::assertNotEmpty($open[1]);
        foreach ($open[1] as $paragraph) {
            self::assertMatchesRegularExpression('/\b(work|owner|run|records?)\b/i', $paragraph, 'An Open paragraph names no work: '.$paragraph);
        }
    }

    public function test_the_counting_note_dates_and_quotes_each_entry(): void
    {
        $source = $this->source();

        if (1 !== preg_match('/^## How we counted\n(.*?)^## Sources/ms', $source, $note)) {
            self::fail('The FAQ has no How we counted section.');
        }
        $entries = array_values(array_filter(explode("\n", $note[1]), static fn (string $line): bool => 1 === preg_match('/^\d+\. /', $line)));
        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            self::assertMatchesRegularExpression('/Read on \d{4}-\d{2}-\d{2}\./', $entry);
            self::assertMatchesRegularExpression('/`[^`]+`/', $entry, 'An entry has no query or command in a code span.');
        }
    }

    public function test_no_list_items_run_together(): void
    {
        self::assertDoesNotMatchRegularExpression('/[a-z.]- [A-Z]/', $this->source());
    }

    private function faq(): FaqPage
    {
        $faq = static::getContainer()->get(FaqPage::class);
        self::assertInstanceOf(FaqPage::class, $faq);

        return $faq;
    }

    private function source(): string
    {
        return (string) file_get_contents(self::FILE);
    }
}
