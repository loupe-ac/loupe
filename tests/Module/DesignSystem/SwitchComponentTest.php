<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

final class SwitchComponentTest extends KernelTestCase
{
    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render($context);
    }

    public function test_a_bare_switch_is_a_checkbox_with_the_switch_role(): void
    {
        $html = $this->render('<twig:Ds:Switch name="archived" />');

        $input = new Crawler($html)->filter('input.lp-switch');
        self::assertCount(1, $input);
        self::assertSame('checkbox', $input->attr('type'));
        self::assertSame('switch', $input->attr('role'));
        self::assertSame('archived', $input->attr('name'));
        self::assertSame('1', $input->attr('value'));
        self::assertNull($input->attr('checked'));
        self::assertStringNotContainsString('lp-switch-field', $html);
    }

    public function test_checked_and_disabled_reach_the_input(): void
    {
        $input = new Crawler($this->render('<twig:Ds:Switch name="a" :checked="true" :disabled="true" data-action="x" />'))->filter('input');

        self::assertNotNull($input->attr('checked'));
        self::assertNotNull($input->attr('disabled'));
        self::assertSame('x', $input->attr('data-action'));
    }

    public function test_a_label_and_a_help_line_are_tied_to_the_input(): void
    {
        $crawler = new Crawler($this->render('<twig:Ds:Switch id="managed" name="managed" label="Managed" help="Agents push as the app." />'));

        $input = $crawler->filter('.lp-switch-field input.lp-switch');
        self::assertSame('managed-help', $input->attr('aria-describedby'));
        self::assertSame('managed', $crawler->filter('label.lp-switch-field__label')->attr('for'));
        self::assertSame('Managed', $crawler->filter('label.lp-switch-field__label')->text());
        self::assertSame('managed-help', $crawler->filter('.lp-switch-field__help')->attr('id'));
        self::assertSame('Agents push as the app.', $crawler->filter('.lp-switch-field__help')->text());
    }

    public function test_a_label_without_help_sets_no_description(): void
    {
        $input = new Crawler($this->render('<twig:Ds:Switch name="a" label="A" />'))->filter('input');

        self::assertNull($input->attr('aria-describedby'));
    }

    public function test_a_symfony_checkbox_field_draws_the_switch_label_and_state(): void
    {
        self::bootKernel();
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->createNamedBuilder('settings', FormType::class, ['managed' => true], ['csrf_protection' => false])
            ->add('managed', CheckboxType::class, ['label' => 'Managed'])
            ->getForm();

        $html = $this->render('<twig:Ds:Switch :fieldView="form.managed" help="Hint" />', ['form' => $form->createView()]);

        $crawler = new Crawler($html);
        $input = $crawler->filter('input.lp-switch');
        self::assertCount(1, $input);
        self::assertSame('switch', $input->attr('role'));
        self::assertSame('settings[managed]', $input->attr('name'));
        self::assertNotNull($input->attr('checked'));
        self::assertSame('settings_managed-help', $input->attr('aria-describedby'));
        self::assertSame('Managed', $crawler->filter('label.lp-switch-field__label')->text());
    }
}
