<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class ChoiceComponentTest extends KernelTestCase
{
    private function render(string $template): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    public function test_a_choice_draws_a_label_around_its_content(): void
    {
        $row = $this->render('<twig:Ds:Choice data-x><input id="c" type="radio"> Name</twig:Ds:Choice>')->filter('label.lp-choice');

        self::assertCount(1, $row);
        self::assertCount(1, $row->filter('input#c'));
        self::assertNotNull($row->attr('data-x'));
        self::assertNotNull($row->attr('data-ds-choice'));
        self::assertStringNotContainsString('lp-choice--', (string) $row->attr('class'));
    }

    public function test_the_top_variant_adds_its_modifier_class(): void
    {
        $row = $this->render('<twig:Ds:Choice variant="top"><input type="radio"></twig:Ds:Choice>')->filter('label.lp-choice');

        self::assertStringContainsString('lp-choice--top', (string) $row->attr('class'));
    }

    public function test_a_null_attribute_is_left_out(): void
    {
        $row = $this->render('<twig:Ds:Choice :data-x="null" :data-y="\'1\'"><input type="radio"></twig:Ds:Choice>')->filter('label.lp-choice');

        self::assertNull($row->attr('data-x'));
        self::assertSame('1', $row->attr('data-y'));
    }

    public function test_the_div_form_draws_the_row_without_a_label(): void
    {
        $row = $this->render('<twig:Ds:Choice as="div"><input type="radio"></twig:Ds:Choice>')->filter('div.lp-choice');

        self::assertCount(1, $row);
        self::assertCount(0, $this->render('<twig:Ds:Choice as="div"><input type="radio"></twig:Ds:Choice>')->filter('label'));
    }

    public function test_the_control_sizing_rules_leave_a_text_field_alone(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/components/choice.css');

        self::assertDoesNotMatchRegularExpression('/\.lp-choice(--top)? input\s*\{/', $css);
        self::assertStringContainsString(".lp-choice input:is([type='checkbox'], [type='radio'])", $css);
    }
}
