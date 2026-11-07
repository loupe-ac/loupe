<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class DisclosureComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_disclosure_is_a_closed_details_element_with_a_summary_and_a_body(): void
    {
        $crawler = new Crawler($this->render('<twig:Ds:Disclosure><twig:block name="summary">Title</twig:block>Body</twig:Ds:Disclosure>'));

        $details = $crawler->filter('details.lp-disclosure');
        self::assertCount(1, $details);
        self::assertNull($details->attr('open'));
        self::assertSame('Title', trim($details->filter('summary.lp-disclosure__summary')->text()));
        self::assertCount(1, $details->filter('summary .lp-disclosure__caret'));
        self::assertSame('Body', trim($details->filter('.lp-disclosure__body')->text()));
    }

    public function test_the_caret_sits_at_the_start_by_default_and_at_the_end_on_request(): void
    {
        $start = new Crawler($this->render('<twig:Ds:Disclosure><twig:block name="summary">T</twig:block>B</twig:Ds:Disclosure>'));
        self::assertCount(1, $start->filter('summary > .lp-disclosure__caret:first-child'));
        self::assertCount(0, $start->filter('.lp-disclosure__caret--end'));

        $end = new Crawler($this->render('<twig:Ds:Disclosure caret="end"><twig:block name="summary">T</twig:block>B</twig:Ds:Disclosure>'));
        self::assertCount(1, $end->filter('summary > .lp-disclosure__caret.lp-disclosure__caret--end:last-child'));
        self::assertCount(1, $end->filter('summary .lp-disclosure__caret'));
    }

    public function test_caret_none_draws_no_caret_and_leaves_room_for_the_page_to_draw_one(): void
    {
        $bare = new Crawler($this->render('<twig:Ds:Disclosure caret="none"><twig:block name="summary">T</twig:block>B</twig:Ds:Disclosure>'));
        self::assertCount(0, $bare->filter('.lp-disclosure__caret'));

        $own = new Crawler($this->render('<twig:Ds:Disclosure caret="none"><twig:block name="summary">T <twig:ux:icon name="lucide:chevron-right" class="lp-disclosure__caret" /></twig:block>B</twig:Ds:Disclosure>'));
        self::assertCount(1, $own->filter('summary .lp-disclosure__caret'));
    }

    public function test_the_caret_turns_for_any_descendant_of_the_summary(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../assets/styles/components/disclosure.css');

        self::assertStringContainsString('.lp-disclosure[open] > .lp-disclosure__summary .lp-disclosure__caret', $css);
    }

    public function test_the_open_prop_opens_the_section(): void
    {
        $details = new Crawler($this->render('<twig:Ds:Disclosure :open="true"><twig:block name="summary">T</twig:block>B</twig:Ds:Disclosure>'))->filter('details');

        self::assertNotNull($details->attr('open'));
    }

    public function test_other_attributes_reach_the_details_element(): void
    {
        $details = new Crawler($this->render('<twig:Ds:Disclosure class="extra" data-controller="details-memory" data-details-memory-key-value="k" data-x><twig:block name="summary">T</twig:block>B</twig:Ds:Disclosure>'))->filter('details');

        self::assertSame('lp-disclosure extra', $details->attr('class'));
        self::assertSame('details-memory', $details->attr('data-controller'));
        self::assertSame('k', $details->attr('data-details-memory-key-value'));
        self::assertNotNull($details->attr('data-x'));
    }

    public function test_the_fold_animation_is_css_only_and_waits_for_motion_preference(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../assets/styles/components/disclosure.css');

        self::assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\)\s*\{[^@]*::details-content[^@]*grid-template-rows: 0fr[^@]*content-visibility[^@]*allow-discrete/s', $css);
        self::assertStringContainsString('[open]::details-content', $css);
        self::assertStringContainsString('overflow-hidden', $css);
    }
}
