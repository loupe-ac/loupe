<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Catalog;
use App\Tests\Support\InstalledInstance;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthShellTest extends WebTestCase
{
    public function test_the_catalog_lists_the_auth_shell_in_the_layout_group(): void
    {
        $entries = [];
        foreach (self::getContainer()->get(Catalog::class)->entries() as $entry) {
            $entries[$entry->name] = $entry;
        }

        self::assertArrayHasKey('AuthShell', $entries);
        self::assertSame('layout', $entries['AuthShell']->group);
        self::assertSame('lp-auth', $entries['AuthShell']->rootClass);
    }

    public function test_the_login_page_draws_the_shell_and_the_app_form_fields(): void
    {
        $client = self::createClient();
        InstalledInstance::ensure(self::getContainer());
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-auth .lp-auth__card'));
        self::assertCount(2, $crawler->filter('.lp-auth__card .lp-form-field input.lp-input'));
        self::assertCount(1, $crawler->filter('.lp-auth__card button.lp-btn--primary.lp-auth__action'));
        self::assertCount(0, $crawler->filter('.auth-input, .auth-submit, .auth-page'));
    }
}
