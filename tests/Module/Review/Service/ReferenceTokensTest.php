<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\ReferenceTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\IdentityTranslator;

final class ReferenceTokensTest extends TestCase
{
    private ReferenceTokens $tokens;

    protected function setUp(): void
    {
        $this->tokens = new ReferenceTokens();
    }

    public function test_a_card_token_becomes_a_marked_span_with_the_shown_text(): void
    {
        self::assertSame(
            '<p>See <span data-ref-card="1116">card #1116</span> now.</p>',
            $this->tokens->mark('<p>See card:1116 now.</p>'),
        );
    }

    public function test_a_pull_request_token_becomes_a_marked_span_with_the_shown_text(): void
    {
        self::assertSame(
            '<p><span data-ref-pr="acme/loupe#1295">acme/loupe#1295</span></p>',
            $this->tokens->mark('<p>pr:acme/loupe#1295</p>'),
        );
    }

    public function test_a_nested_path_is_one_token(): void
    {
        self::assertSame(
            '<span data-ref-pr="group/sub/repo#7">group/sub/repo#7</span>',
            $this->tokens->mark('pr:group/sub/repo#7'),
        );
    }

    public function test_a_token_may_end_a_sentence(): void
    {
        self::assertSame(
            'Done in <span data-ref-card="5">card #5</span>.',
            $this->tokens->mark('Done in card:5.'),
        );
    }

    /** R3 */
    public function test_a_bracketed_token_stays_as_written(): void
    {
        self::assertSame('<p>[card:1116]</p>', $this->tokens->mark('<p>[card:1116]</p>'));
        self::assertSame('<p>[pr:a/b#1]</p>', $this->tokens->mark('<p>[pr:a/b#1]</p>'));
        self::assertSame('[card:1116](x)', $this->tokens->mark('[card:1116](x)'));
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'after a letter' => ['xcard:1116'];
        yield 'after a digit' => ['9card:1116'];
        yield 'after a colon' => [':card:1116'];
        yield 'after a slash' => ['/card:1116'];
        yield 'after a hash' => ['#card:1116'];
        yield 'before a letter' => ['card:1116x'];
        yield 'before a digit run into a letter' => ['card:12ab'];
        yield 'before a bracket' => ['card:1116]'];
        yield 'no number' => ['card:'];
        yield 'zero' => ['card:0'];
        yield 'leading zero' => ['card:01'];
        yield 'too long' => ['card:12345678901234567890'];
        yield 'pr with one segment' => ['pr:repo#7'];
        yield 'pr after a slash' => ['x/pr:a/b#7'];
        yield 'pr without number' => ['pr:a/b#'];
        yield 'pr with a dot segment' => ['pr:a/./b#7'];
        yield 'pr with a dot dot segment' => ['pr:owner/../b#7'];
        yield 'pr with an empty segment' => ['pr:a//b#7'];
        yield 'pr run into a letter' => ['pr:a/b#7x'];
        yield 'pr run into a bracket' => ['pr:a/b#7]'];
        yield 'a card in a word' => ['discard:12'];
    }

    #[DataProvider('untouched')]
    public function test_text_that_breaks_the_grammar_stays_as_written(string $text): void
    {
        self::assertSame('<p>'.$text.'</p>', $this->tokens->mark('<p>'.$text.'</p>'));
    }

    /** R17 */
    public function test_code_pre_and_links_are_skipped(): void
    {
        $html = '<p><code>card:1</code> <a href="/x">card:2</a></p><pre><code>pr:a/b#3</code></pre>'
            .'<textarea>card:4</textarea><script>card:5</script><style>card:6</style>';

        self::assertSame($html, $this->tokens->mark($html));
    }

    public function test_text_after_a_skipped_element_is_marked_again(): void
    {
        self::assertSame(
            '<code>card:1</code> <span data-ref-card="2">card #2</span>',
            $this->tokens->mark('<code>card:1</code> card:2'),
        );
    }

    public function test_a_token_inside_other_inline_markup_is_marked(): void
    {
        self::assertSame(
            '<strong><span data-ref-card="9">card #9</span></strong>',
            $this->tokens->mark('<strong>card:9</strong>'),
        );
    }

    public function test_a_token_in_an_attribute_value_is_left_alone(): void
    {
        $html = '<img alt="card:7" src="/x.png">';

        self::assertSame($html, $this->tokens->mark($html));
    }

    public function test_two_tokens_in_one_text_are_both_marked(): void
    {
        self::assertSame(
            '<span data-ref-card="1">card #1</span> and <span data-ref-card="2">card #2</span>',
            $this->tokens->mark('card:1 and card:2'),
        );
    }

    public function test_plain_turns_each_token_into_its_shown_text(): void
    {
        self::assertSame('Fix card #1116 in acme/loupe#1295', $this->tokens->plain('Fix card:1116 in pr:acme/loupe#1295'));
        self::assertSame('[card:1116] stays', $this->tokens->plain('[card:1116] stays'));
        self::assertSame('Nothing here', $this->tokens->plain('Nothing here'));
    }

    public function test_the_renderer_marks_tokens_and_keeps_the_text_basis_equal_to_the_shown_text(): void
    {
        $renderer = new MarkdownRenderer(new NullLogger(), new IdentityTranslator(), tokens: $this->tokens);
        $html = $renderer->render("# Plan for card:1116\n\nChild card:1117 builds pr:acme/loupe#1295 and `card:5`, but [card:9] stays.\n");

        self::assertStringContainsString('<span data-ref-card="1117">card #1117</span>', $html);
        self::assertStringContainsString('<span data-ref-pr="acme/loupe#1295">acme/loupe#1295</span>', $html);
        self::assertStringContainsString('<code>card:5</code>', $html);
        self::assertStringContainsString('[card:9]', $html);
        self::assertStringContainsString('id="heading-plan-for-card-1116"', $html);

        $shown = "Plan for card #1116\nChild card #1117 builds acme/loupe#1295 and card:5, but [card:9] stays.\n";
        self::assertSame($shown, DocumentVersion::plainTextOf($html));
    }
}
