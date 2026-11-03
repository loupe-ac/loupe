<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\PageLinks;
use App\Routing\PinnedUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

final class PageLinksTest extends KernelTestCase
{
    private const string PROJECT = '0195f3a0-0000-7000-8000-000000000001';
    private const string OTHER = '0195f3a0-0000-7000-8000-000000000002';

    public function test_each_resource_links_to_its_page_route(): void
    {
        $links = $this->links('https://app.example.com');

        self::assertSame('https://app.example.com/projects/'.self::PROJECT, $links->project(self::PROJECT));
        self::assertSame('https://app.example.com/projects/'.self::PROJECT.'/board/cards/'.self::OTHER, $links->card(self::PROJECT, self::OTHER));
        self::assertSame('https://app.example.com/projects/'.self::PROJECT.'/documents/'.self::OTHER.'/review', $links->document(self::PROJECT, self::OTHER));
        self::assertSame('https://app.example.com/projects/'.self::PROJECT.'/artifacts/'.self::OTHER, $links->artifact(self::PROJECT, self::OTHER));
    }

    public function test_the_link_uses_the_configured_address_and_not_the_request_host(): void
    {
        $router = $this->router();
        $router->setContext(RequestContext::fromUri('http://web:8080'));
        $links = new PageLinks(new PinnedUrlGenerator($router, 'https://loupe.example.com'));

        self::assertSame('https://loupe.example.com/projects/'.self::PROJECT, $links->project(self::PROJECT));
        self::assertSame('web', $router->getContext()->getHost());
    }

    public function test_the_path_of_the_configured_address_is_kept(): void
    {
        $links = $this->links('https://app.example.com/loupe/');

        self::assertStringStartsWith('https://app.example.com/loupe/projects/', $links->project(self::PROJECT));
        self::assertStringNotContainsString('//projects', $links->project(self::PROJECT));
    }

    public function test_a_note_with_a_card_links_to_the_feedback_tab_at_the_note(): void
    {
        $link = $this->links('https://app.example.com')->feedback(self::PROJECT, self::OTHER, 'note-1');

        self::assertSame('https://app.example.com/projects/'.self::PROJECT.'/board/cards/'.self::OTHER.'?tab=feedback#feedback-note-1', $link);
    }

    public function test_a_note_with_no_card_has_no_link(): void
    {
        self::assertNull($this->links('https://app.example.com')->feedback(self::PROJECT, null, 'note-1'));
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get('router');
    }

    private function links(string $defaultUri): PageLinks
    {
        return new PageLinks(new PinnedUrlGenerator($this->router(), $defaultUri));
    }
}
