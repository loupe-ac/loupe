<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class WorkspaceParityTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_the_default_width_draws_the_bare_frame_and_passes_attributes_through(): void
    {
        $html = $this->render('<twig:Ds:Workspace class="lp-redesign-settings" data-x="1">Body</twig:Ds:Workspace>');

        self::assertStringContainsString('<div class="lp-workspace lp-redesign-settings" data-x="1">Body</div>', $html);
    }

    public function test_a_named_width_adds_its_modifier(): void
    {
        self::assertStringContainsString('class="lp-workspace lp-workspace--narrow"', $this->render('<twig:Ds:Workspace width="narrow" />'));
        self::assertStringContainsString('class="lp-workspace lp-workspace--full"', $this->render('<twig:Ds:Workspace width="full" />'));
    }

    public function test_the_split_view_draws_the_same_frame_when_it_gets_a_width(): void
    {
        self::assertStringContainsString('class="lp-split-page"', $this->render('<twig:Ds:SplitView />'));
        self::assertStringContainsString('class="lp-split-page lp-workspace lp-workspace--full"', $this->render('<twig:Ds:SplitView width="full" />'));
        self::assertStringContainsString('class="lp-split-page lp-workspace"', $this->render('<twig:Ds:SplitView width="default" />'));
    }
}
