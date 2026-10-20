<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class StepperComponentTest extends KernelTestCase
{
    private const string STEPS = ":steps=\"['Project', 'Your machine', 'Ready for agents']\"";

    private function render(string $template): Crawler
    {
        self::bootKernel();

        return new Crawler(static::getContainer()->get(Environment::class)->createTemplate($template)->render());
    }

    public function test_the_list_carries_the_wizard_marker_and_passes_attributes(): void
    {
        $list = $this->render('<twig:Ds:Stepper '.self::STEPS.' :current="2" aria-label="Setup progress" />')->filter('ol.lp-stepper');

        self::assertCount(1, $list);
        self::assertSame('2', $list->attr('data-wizard-step'));
        self::assertSame('Setup progress', $list->attr('aria-label'));
    }

    public function test_each_step_has_the_state_of_its_position(): void
    {
        $crawler = $this->render('<twig:Ds:Stepper '.self::STEPS.' :current="2" />');

        $steps = $crawler->filter('li.lp-stepper__step');
        self::assertCount(3, $steps);
        self::assertStringContainsString('lp-stepper__step--done', (string) $steps->eq(0)->attr('class'));
        self::assertStringContainsString('lp-stepper__step--current', (string) $steps->eq(1)->attr('class'));
        self::assertStringContainsString('lp-stepper__step--upcoming', (string) $steps->eq(2)->attr('class'));
        self::assertCount(2, $crawler->filter('li.lp-stepper__bar[aria-hidden="true"]'));
    }

    public function test_only_the_current_step_is_marked_and_numbers_replace_the_check_after_it(): void
    {
        $crawler = $this->render('<twig:Ds:Stepper '.self::STEPS.' :current="2" />');

        self::assertCount(1, $crawler->filter('[aria-current="step"]'));
        self::assertStringContainsString('Your machine', $crawler->filter('[aria-current="step"]')->text());
        self::assertCount(1, $crawler->filter('li.lp-stepper__step--done svg'));
        self::assertSame(['2', '3'], $crawler->filter('.lp-stepper__number')->each(static fn (Crawler $number): string => $number->text()));
    }

    public function test_complete_marks_every_step_done(): void
    {
        $crawler = $this->render('<twig:Ds:Stepper '.self::STEPS.' :current="3" :complete="true" />');

        self::assertCount(3, $crawler->filter('li.lp-stepper__step--done'));
        self::assertCount(0, $crawler->filter('.lp-stepper__number'));
        self::assertCount(0, $crawler->filter('[aria-current="step"]'));
    }
}
