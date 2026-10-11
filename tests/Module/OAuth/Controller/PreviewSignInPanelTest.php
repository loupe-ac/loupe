<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller;

use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class PreviewSignInPanelTest extends WebTestCase
{
    use PreviewTokenScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->setUpScenario('preview-panel-owner@example.com');
    }

    public function test_a_card_with_a_live_token_shows_it_with_a_revoke_and_a_link_to_the_list(): void
    {
        $cardId = $this->cardOf($this->project);
        $id = $this->mint('pr-12', $cardId);
        $this->connection->executeStatement(
            "UPDATE oauth_preview_tokens SET last_used_at = NOW() - INTERVAL '2 hours' WHERE access_token_id = :id",
            ['id' => $id],
        );

        $this->showCard($cardId);

        self::assertResponseIsSuccessful();
        $panel = '[data-preview-sign-in]';
        $token = $panel.' [data-preview-sign-in-token="'.$id.'"]';
        self::assertSelectorTextContains($panel, 'Preview sign-in');
        self::assertSelectorTextContains($token.' .lp-status-chip--ok', 'Active');
        self::assertSelectorTextContains($token, 'In 14 days');
        self::assertSelectorTextContains($token, '2h ago');
        self::assertSelectorExists($panel.' > .lp-section-heading form[action$="/preview-tokens/'.$id.'/revoke/to-card"]');
        self::assertSelectorExists($panel.' a[href="/account/connected-apps"]');
    }

    public function test_a_card_with_two_live_tokens_shows_one_section_with_a_labelled_group_and_a_revoke_for_each(): void
    {
        $cardId = $this->cardOf($this->project);
        $first = $this->mint('pr-12', $cardId);
        $second = $this->mint('pr-13', $cardId);
        $this->connection->executeStatement(
            "UPDATE oauth2_access_token SET expiry = NOW() + INTERVAL '5 hours' WHERE identifier = :id",
            ['id' => $second],
        );

        $crawler = $this->showCard($cardId);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-preview-sign-in]'));
        self::assertCount(1, $crawler->filter('[data-preview-sign-in] .lp-card-panel__title'));
        self::assertCount(1, $crawler->filter('[data-preview-sign-in] a[href="/account/connected-apps"]'));
        self::assertCount(0, $crawler->filter('[data-preview-sign-in] > .lp-section-heading form'));
        foreach (['pr-12' => $first, 'pr-13' => $second] as $label => $id) {
            $token = '[data-preview-sign-in] [data-preview-sign-in-token="'.$id.'"]';
            self::assertSelectorTextContains($token, $label);
            self::assertCount(1, $crawler->filter($token.' form[action$="/preview-tokens/'.$id.'/revoke/to-card"]'));
        }
        self::assertSelectorTextContains('[data-preview-sign-in-token="'.$first.'"]', 'In 14 days');
        self::assertSelectorTextContains('[data-preview-sign-in-token="'.$second.'"]', 'In less than a day');
    }

    public function test_a_card_with_no_live_token_of_the_viewer_shows_the_empty_line(): void
    {
        $cardId = $this->cardOf($this->project);
        $this->mint('pr-12', $this->cardOf($this->project, 2));

        $this->showCard($cardId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-preview-sign-in]', 'No live preview tokens.');
        self::assertSelectorExists('[data-preview-sign-in] a[href="/account/connected-apps"]');
        self::assertSelectorNotExists('[data-preview-sign-in] form');
    }

    public function test_revoking_from_the_panel_returns_to_the_card_with_the_token_revoked(): void
    {
        $cardId = $this->cardOf($this->project);
        $id = $this->mint('pr-12', $cardId);

        $crawler = $this->showCard($cardId);
        $this->client->submit($crawler->filter('[data-preview-sign-in] form[action$="/preview-tokens/'.$id.'/revoke/to-card"]')->form());

        self::assertResponseRedirects('/projects/'.$this->project->id.'/board/cards/'.$cardId);
        self::assertTrue($this->isRevoked($id));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The preview token no longer works.');
        self::assertSelectorTextContains('[data-preview-sign-in]', 'No live preview tokens.');
    }

    private function showCard(Uuid $cardId): Crawler
    {
        $this->client->loginUser($this->owner);

        return $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/board/cards/'.$cardId);
    }
}
