<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class CodeBlockComponentTest extends KernelTestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render($context);
    }

    public function test_the_block_draws_its_label_body_and_a_copy_button_of_the_same_text(): void
    {
        $html = $this->render('<twig:Ds:CodeBlock label="Terminal" :text="text" copyLabel="Copy" copiedLabel="Copied!" testid="snippet" />', ['text' => 'a & b']);

        self::assertStringContainsString('class="lp-code-dark"', $html);
        self::assertStringContainsString('<span class="lp-code-dark__label">Terminal</span>', $html);
        self::assertStringContainsString('<pre class="lp-code-dark__body" data-overflow-cue-target="viewport"><code data-testid="snippet">a &amp; b</code></pre>', $html);
        self::assertStringContainsString('data-clipboard-text-value="a &amp; b"', $html);
        self::assertStringContainsString('lp-copy-button__chip', $html);
        self::assertStringContainsString('>Copy</button>', $html);
    }

    public function test_the_code_sits_in_an_overflow_cue_wrapper_with_no_arrows(): void
    {
        $html = $this->render('<twig:Ds:CodeBlock label="Terminal" text="x" copyLabel="Copy" />');

        self::assertStringContainsString('<div class="lp-overflow-cue lp-code-dark__scroll" data-controller="overflow-cue">', $html);
        self::assertStringNotContainsString('lp-overflow-cue__arrow', $html);
    }

    public function test_copy_false_draws_no_button_and_no_controller(): void
    {
        $html = $this->render('<twig:Ds:CodeBlock label="Read only" text="x" :copy="false" class="extra" />');

        self::assertStringNotContainsString('clipboard', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringContainsString('class="lp-code-dark extra"', $html);
        self::assertStringContainsString('<pre class="lp-code-dark__body" data-overflow-cue-target="viewport"><code>x</code></pre>', $html);
    }

    public function test_the_body_keeps_line_breaks_of_the_text(): void
    {
        $html = $this->render('<twig:Ds:CodeBlock label="L" :text="text" :copy="false" />', ['text' => "one\ntwo"]);

        self::assertStringContainsString("<code>one\ntwo</code>", $html);
    }

    public function test_the_meta_block_follows_the_label_and_the_tail_block_follows_the_code(): void
    {
        $html = $this->render('<twig:Ds:CodeBlock label="Output" text="a" :copy="false"><twig:block name="meta"><span class="marker">Live</span></twig:block><twig:block name="tail"><i class="cursor"></i></twig:block></twig:Ds:CodeBlock>');

        self::assertStringContainsString('<span class="lp-code-dark__label">Output</span><span class="marker">Live</span>', $html);
        self::assertStringContainsString('<code>a</code><i class="cursor"></i></pre>', $html);
    }
}
