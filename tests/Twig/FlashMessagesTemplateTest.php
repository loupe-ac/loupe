<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Module\Readiness\Command\StartDiscoveryHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class FlashMessagesTemplateTest extends KernelTestCase
{
    public function test_a_command_in_a_flash_message_renders_as_code(): void
    {
        self::bootKernel();
        $message = static::getContainer()->get(TranslatorInterface::class)->trans(StartDiscoveryHandler::NO_CAPABLE_BRIDGE_APP_PROMPTS);

        $html = static::getContainer()->get(Environment::class)->render('_flash_messages.html.twig', ['flashes' => ['error' => [$message]]]);

        self::assertStringContainsString('<code>loupe init</code>', $html);
        self::assertStringNotContainsString('`', $html);
    }

    public function test_markup_in_a_flash_message_stays_escaped(): void
    {
        self::bootKernel();

        $html = static::getContainer()->get(Environment::class)->render('_flash_messages.html.twig', ['flashes' => ['error' => ['<b>x</b>']]]);

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
    }

    public function test_the_flash_stream_prepends_one_flash_to_the_page(): void
    {
        self::bootKernel();

        $html = static::getContainer()->get(Environment::class)->render('_flash.stream.html.twig', ['severity' => 'warning', 'message' => '<b>x</b> `loupe init`']);

        self::assertStringContainsString('<turbo-stream action="prepend" target="main-content">', $html);
        self::assertStringContainsString('lp-flash--warning', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt; <code>loupe init</code>', $html);
    }
}
