<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class BetaWelcomeEmailTemplateTest extends KernelTestCase
{
    public function test_it_shows_both_buttons_when_a_discord_link_is_passed(): void
    {
        $html = $this->render('https://discord.gg/example');

        self::assertStringContainsString('Welcome to the Loupe beta', $html);
        self::assertStringContainsString('https://app.example/', $html);
        self::assertStringContainsString('Open Loupe', $html);
        self::assertStringContainsString('https://discord.gg/example', $html);
        self::assertStringContainsString('Join the Discord server', $html);
        self::assertStringContainsString('talk on Discord', $html);
        self::assertStringContainsString('You get this email because you used a Loupe beta invite.', $html);
    }

    public function test_it_shows_neither_the_sentence_nor_the_button_without_a_discord_link(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('Open Loupe', $html);
        self::assertStringContainsString('free access for as long as the beta runs', $html);
        self::assertStringNotContainsString('Discord', $html);
    }

    private function render(?string $discordUrl): string
    {
        self::bootKernel();

        return self::getContainer()->get(Environment::class)->render('@Billing/email/beta_welcome.html.twig', [
            'appUrl' => 'https://app.example/',
            'discordUrl' => $discordUrl,
        ]);
    }
}
