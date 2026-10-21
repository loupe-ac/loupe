<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Service;

use App\Module\Landing\Service\ChromeCssExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChromeCssExtractorTest extends TestCase
{
    private const string CSS = <<<'CSS'
        /*! tailwindcss */
        @layer properties;
        @layer theme, base, components, utilities;
        @layer theme {
          :root, :host {
            --spacing: 0.25rem;
            --color-red-50: red;
            --font-sans: 'DM Sans', sans-serif;
          }
        }
        @layer base {
          *, ::after, ::before { box-sizing: border-box; margin: 0; }
          html, :host { font-family: var(--font-sans); }
          [hidden]:where(:not([hidden='until-found'])) { display: none !important; }
        }
        @layer utilities {
          .w-4 { width: calc(var(--spacing) * 4); }
          .w-9 { width: 9px; }
        }
        :root, :host {
          --chrome: #181d18;
          --accent: #d4e94c;
          --unused-token: #fff;
        }
        @layer components {
          .lp-home-nav { background-color: var(--chrome); padding: var(--pad, 1px); }
          .lp-home-nav__link--section {
            display: none;
            @media (width >= 64rem) { display: inline; }
          }
          .lp-home-nav__link:hover { color: var(--accent); }
          .lp-review .lp-review-doc__prose { color: red; }
          .lp-home-footer { border-top-style: var(--tw-border-style); }
          @media (pointer: coarse) {
            .lp-home-nav__link, .lp-review-doc__prose { padding: 1px; }
          }
          @media (width >= 40rem) { .lp-review-doc__prose { padding: 2px; } }
        }
        @font-face { font-family: Geist; src: url(x.woff2); }
        @property --tw-border-style { syntax: "*"; inherits: false; initial-value: solid; }
        @property --tw-leading { syntax: "*"; inherits: false; }
        CSS;

    private const string HTML = '<header class="lp-home-nav"><a class="lp-home-nav__link lp-home-nav__link--section w-4" href="#">x</a><b class="lp-home-footer"></b></header>';

    public function test_it_keeps_the_rules_of_the_classes_in_the_markup(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, self::HTML);

        self::assertStringContainsString('.lp-home-nav {', $css);
        self::assertStringContainsString('.lp-home-nav__link:hover', $css);
        self::assertStringContainsString('.w-4 {', $css);
        self::assertStringContainsString('@media (width >= 64rem)', $css);
        self::assertStringContainsString('box-sizing: border-box', $css);
    }

    public function test_it_drops_rules_that_name_a_class_the_markup_lacks(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, self::HTML);

        self::assertStringNotContainsString('lp-review', $css);
        self::assertStringNotContainsString('.w-9', $css);
        self::assertStringNotContainsString('@font-face', $css);
        self::assertStringNotContainsString('/*', $css);
    }

    public function test_it_keeps_the_selectors_of_a_list_that_the_markup_uses(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, self::HTML);

        self::assertMatchesRegularExpression('/@media \(pointer: coarse\)\s*\{\s*\.lp-home-nav__link\s*\{/', $css);
    }

    /** The site bar hides one of its two primary actions, and `.lp-btn` sets a display that only this rule beats. */
    public function test_it_keeps_the_base_rule_that_hides_a_hidden_element(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, '<p>plain</p>');

        self::assertStringContainsString("[hidden]:where(:not([hidden='until-found'])) { display: none !important; }", $css);
    }

    public function test_it_keeps_only_the_custom_properties_that_the_kept_rules_use(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, self::HTML);

        foreach (['--spacing:', '--chrome:', '--accent:', '--font-sans:'] as $declaration) {
            self::assertStringContainsString($declaration, $css);
        }
        self::assertStringNotContainsString('--color-red-50', $css);
        self::assertStringNotContainsString('--unused-token', $css);
        self::assertStringContainsString(':host', $css);
    }

    public function test_it_gives_a_registered_property_its_initial_value_inside_the_shadow_root(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, self::HTML);

        self::assertStringNotContainsString('@property', $css);
        self::assertMatchesRegularExpression('/@layer properties\s*\{[^}]*\*[^}]*--tw-border-style: solid/s', $css);
        self::assertStringNotContainsString('--tw-leading', $css);
    }

    public function test_it_keeps_the_layer_order_statement(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, self::HTML);

        self::assertStringContainsString('@layer properties, theme, base, components, utilities;', $css);
    }

    /** @return iterable<string, array{string, string}> */
    public static function escapedSelectors(): iterable
    {
        yield 'escaped quote' => [".\\[\\&_svg\\:not\\(\\[class\\*\\=\\'size-\\'\\]\\)\\]\\:size-4 { width: 1rem; }", "[&_svg:not([class*='size-'])]:size-4"];
        yield 'escaped brace' => ['.a\\{b { width: 2px; }', 'a{b'];
        yield 'escaped bracket' => ['.c\\[d { width: 3px; }', 'c[d'];
    }

    #[DataProvider('escapedSelectors')]
    public function test_an_escaped_character_in_a_selector_does_not_hide_the_rules_after_it(string $rule, string $class): void
    {
        $stylesheet = "@layer utilities {\n  ".$rule."\n  .w-4 { width: 4px; }\n}\n@layer components {\n  .lp-home-nav { color: red; }\n}\n";
        $markup = '<header class="lp-home-nav w-4"><i class="'.htmlspecialchars($class).'"></i></header>';

        $css = new ChromeCssExtractor()->extract($stylesheet, $markup);

        self::assertStringContainsString($rule, $css);
        self::assertStringContainsString('.w-4 { width: 4px; }', $css);
        self::assertStringContainsString('.lp-home-nav { color: red; }', $css);
    }

    public function test_markup_with_no_known_class_gives_the_base_alone(): void
    {
        $css = new ChromeCssExtractor()->extract(self::CSS, '<p>plain</p>');

        self::assertStringNotContainsString('.lp-home-nav', $css);
        self::assertStringContainsString('box-sizing', $css);
    }
}
