<?php

declare(strict_types=1);

namespace App\Tests\Module\Legal\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowLegalPagesControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPages(): iterable
    {
        yield 'landing' => ['/'];
        yield 'privacy' => ['/privacy'];
        yield 'terms' => ['/terms'];
        yield 'ai policy' => ['/ai-policy'];
        yield 'sign in' => ['/login'];
    }

    #[DataProvider('publicPages')]
    public function test_each_public_footer_links_the_three_policies(string $path): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        foreach (['/privacy', '/terms', '/ai-policy'] as $policy) {
            self::assertSelectorExists(sprintf('footer a[href="%s"], .lp-auth__footer a[href="%s"]', $policy, $policy));
        }
    }

    public function test_a_legal_page_draws_the_marketing_frame_for_a_guest(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/terms');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('div.lp-landing > header.lp-landing-nav');
        self::assertSelectorCount(1, 'main#main-content');
        self::assertSelectorExists('main#main-content article.lp-legal');
        self::assertSelectorExists('div.lp-landing > footer.lp-landing-footer');
    }

    public function test_the_landing_page_keeps_its_own_header_footer_and_dialog(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('div.lp-home[data-controller="modal"] > header.lp-home-nav');
        self::assertSelectorCount(1, 'main#main-content');
        self::assertSelectorExists('div.lp-home > footer.lp-home-footer');
        self::assertSelectorExists('div.lp-home dialog');
    }
}
