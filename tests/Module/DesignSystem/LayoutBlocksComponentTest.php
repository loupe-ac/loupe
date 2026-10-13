<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class LayoutBlocksComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_a_panel_is_a_div_unless_told_otherwise(): void
    {
        self::assertStringContainsString('<div class="lp-panel">Body</div>', $this->render('<twig:Ds:Panel>Body</twig:Ds:Panel>'));
        self::assertStringContainsString('<section class="lp-panel" id="x">Body</section>', $this->render('<twig:Ds:Panel as="section" id="x">Body</twig:Ds:Panel>'));
    }

    public function test_a_section_heading_renders_its_title_level_and_description(): void
    {
        $html = $this->render('<twig:Ds:SectionHeading :level="3" description="Why" titleId="t">Title</twig:Ds:SectionHeading>');

        self::assertStringContainsString('<h3 class="lp-section-heading__title" id="t">Title</h3>', $html);
        self::assertStringContainsString('<p class="lp-section-heading__description">Why</p>', $html);
        self::assertStringContainsString('<div class="lp-section-heading__actions"></div>', $html);
    }

    public function test_a_section_heading_renders_its_actions_block(): void
    {
        $html = $this->render('<twig:Ds:SectionHeading>Title<twig:block name="actions"><a href="/x">Go</a></twig:block></twig:Ds:SectionHeading>');

        self::assertStringContainsString('<h2 class="lp-section-heading__title">Title</h2>', $html);
        self::assertStringContainsString('<div class="lp-section-heading__actions"><a href="/x">Go</a></div>', $html);
    }

    public function test_form_actions_align_end_by_default_and_between_on_request(): void
    {
        self::assertStringContainsString('<div class="lp-form-actions">Go</div>', $this->render('<twig:Ds:FormActions>Go</twig:Ds:FormActions>'));
        self::assertStringContainsString('class="lp-form-actions lp-form-actions--between"', $this->render('<twig:Ds:FormActions align="between">Go</twig:Ds:FormActions>'));
    }
}
