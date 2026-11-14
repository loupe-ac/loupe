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
        self::assertStringNotContainsString('lp-choice--', (string) $row->attr('class'));
    }

    public function test_the_top_variant_adds_its_modifier_class(): void
    {
        $row = $this->render('<twig:Ds:Choice variant="top"><input type="radio"></twig:Ds:Choice>')->filter('label.lp-choice');

        self::assertStringContainsString('lp-choice--top', (string) $row->attr('class'));
    }
}
