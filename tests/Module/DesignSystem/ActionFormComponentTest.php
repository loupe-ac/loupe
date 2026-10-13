<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ActionFormComponentTest extends KernelTestCase
{
    private function render(string $template): string
    {
        self::bootKernel();

        return static::getContainer()->get(Environment::class)->createTemplate($template)->render();
    }

    public function test_it_renders_a_post_form_with_the_button_as_content(): void
    {
        $html = $this->render('<twig:Ds:ActionForm action="/archive" token="logout"><button type="submit">Archive</button></twig:Ds:ActionForm>');

        self::assertStringContainsString('<form class="lp-action-form" method="post" action="/archive">', $html);
        self::assertStringContainsString('<button type="submit">Archive</button></form>', $html);
        self::assertStringNotContainsString('data-turbo-frame', $html);
    }

    public function test_the_hidden_field_holds_what_csrf_token_gives_and_not_the_token_id(): void
    {
        $html = $this->render('<twig:Ds:ActionForm action="/x" token="logout">x</twig:Ds:ActionForm>');
        $expected = $this->render("{{ csrf_token('logout') }}");

        self::assertNotSame('logout', $expected);
        self::assertStringContainsString('<input type="hidden" name="_csrf_token" value="'.$expected.'">', $html);
    }

    public function test_a_frame_sets_the_turbo_frame(): void
    {
        $html = $this->render('<twig:Ds:ActionForm action="/x" token="logout" frame="_top">x</twig:Ds:ActionForm>');

        self::assertStringContainsString(' data-turbo-frame="_top"', $html);
    }

    public function test_the_class_and_other_attributes_pass_to_the_form(): void
    {
        $html = $this->render('<twig:Ds:ActionForm action="/x" token="logout" class="mt-1" data-turbo="false">x</twig:Ds:ActionForm>');

        self::assertStringContainsString('class="lp-action-form mt-1"', $html);
        self::assertStringContainsString('data-turbo="false"', $html);
    }
}
