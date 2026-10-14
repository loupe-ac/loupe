<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class BrandMarkComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_the_default_draws_the_mark_and_the_brand_name(): void
    {
        $html = $this->render('<twig:Ds:BrandMark />');

        self::assertStringContainsString('class="lp-brand lp-brand--md"', $html);
        self::assertStringContainsString('<span class="lp-brand__mark" aria-hidden="true"></span>', $html);
        self::assertStringContainsString('<span class="lp-brand__word">loupe</span>', $html);
    }

    public function test_the_small_size_adds_its_modifier(): void
    {
        self::assertStringContainsString('class="lp-brand lp-brand--sm"', $this->render('<twig:Ds:BrandMark size="sm" />'));
    }

    public function test_mark_only_names_the_square_and_drops_the_word(): void
    {
        $html = $this->render('<twig:Ds:BrandMark :markOnly="true" />');

        self::assertStringContainsString('lp-brand--mark-only', $html);
        self::assertStringContainsString('<span class="lp-brand__mark" role="img" aria-label="loupe"></span>', $html);
        self::assertStringNotContainsString('lp-brand__word', $html);
    }
}
