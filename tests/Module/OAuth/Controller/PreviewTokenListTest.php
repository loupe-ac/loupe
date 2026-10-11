<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Controller;

use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class PreviewTokenListTest extends WebTestCase
{
    use PreviewTokenScenario;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->setUpScenario('preview-list-owner@example.com');
    }

    public function test_the_list_shows_each_live_token_with_its_card_project_and_expiry(): void
    {
        $id = $this->mint('pr-12', $this->cardOf($this->project, 707));

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertResponseIsSuccessful();
        $row = '[data-preview-token="'.$id.'"]';
        self::assertSelectorTextContains($row, '#707');
        self::assertSelectorTextContains($row, 'Preview it');
        self::assertSelectorTextContains($row, 'Preview site');
        self::assertSelectorTextContains($row, 'Expires in 13 days');
        self::assertSelectorTextContains($row, 'Not used yet');
        self::assertSelectorExists('[data-preview-widget] [data-preview-widget-revoke-all]');
    }

    public function test_a_token_with_no_card_reads_no_card_and_a_used_token_shows_its_last_use(): void
    {
        $id = $this->mint('pr-12');
        $this->connection->executeStatement(
            "UPDATE oauth_preview_tokens SET last_used_at = NOW() - INTERVAL '2 hours' WHERE access_token_id = :id",
            ['id' => $id],
        );
        $this->connection->executeStatement(
            "UPDATE oauth2_access_token SET expiry = NOW() + INTERVAL '5 hours' WHERE identifier = :id",
            ['id' => $id],
        );

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        $row = '[data-preview-token="'.$id.'"]';
        self::assertSelectorTextContains($row, 'No card');
        self::assertSelectorTextContains($row, 'Expires today');
        self::assertSelectorTextContains($row, 'Last used 2h ago');
    }

    public function test_revoking_one_row_leaves_the_other_token_live(): void
    {
        $target = $this->mint('pr-12');
        $other = $this->mint('pr-13');

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');
        $this->client->submit($crawler->filter('[data-preview-token="'.$target.'"] form')->form());

        self::assertResponseRedirects('/account/connected-apps');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The preview token no longer works.');
        self::assertSelectorNotExists('[data-preview-token="'.$target.'"]');
        self::assertSelectorExists('[data-preview-token="'.$other.'"]');
        self::assertTrue($this->isRevoked($target));
        self::assertFalse($this->isRevoked($other));
    }

    public function test_with_no_live_token_the_widget_group_says_so_and_offers_no_revoke_all(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-preview-widget]', 'No live preview tokens.');
        self::assertSelectorNotExists('[data-preview-widget-revoke-all]');
        self::assertSelectorNotExists('[data-preview-token]');
    }

    public function test_revoke_all_ends_every_preview_token_of_the_person(): void
    {
        $first = $this->mint('pr-12');
        $second = $this->mint('pr-13');

        $this->client->loginUser($this->owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');
        $this->client->submit($crawler->filter('[data-preview-widget-revoke-all] form')->form());

        self::assertResponseRedirects('/account/connected-apps');
        self::assertTrue($this->isRevoked($first));
        self::assertTrue($this->isRevoked($second));
    }

    public function test_another_persons_token_is_not_revoked(): void
    {
        $stranger = $this->scenario->createUser('preview-list-stranger@example.com');
        $theirs = $this->mint('pr-12', owner: $stranger, project: $this->scenario->createProject($stranger, 'Their site'));

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/account/connected-apps');
        $this->client->request(Request::METHOD_POST, '/account/connected-apps/preview-tokens/'.$theirs.'/revoke', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/account/connected-apps');
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('body', 'The preview token no longer works.');
        self::assertFalse($this->isRevoked($theirs));
    }

    public function test_a_revoke_from_the_card_returns_to_the_card(): void
    {
        $cardId = $this->cardOf($this->project);
        $id = $this->mint('pr-12', $cardId);

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/account/connected-apps');
        $this->client->request(Request::METHOD_POST, '/account/connected-apps/preview-tokens/'.$id.'/revoke/to-card', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/projects/'.$this->project->id.'/board/cards/'.$cardId);
        self::assertTrue($this->isRevoked($id));
    }

    public function test_a_revoke_to_the_card_of_a_token_with_no_card_returns_to_the_list(): void
    {
        $id = $this->mint('pr-12');

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_GET, '/account/connected-apps');
        $this->client->request(Request::METHOD_POST, '/account/connected-apps/preview-tokens/'.$id.'/revoke/to-card', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/account/connected-apps');
        self::assertTrue($this->isRevoked($id));
    }

    public function test_a_revoke_with_a_bad_csrf_token_is_refused(): void
    {
        $id = $this->mint('pr-12');

        $this->client->loginUser($this->owner);
        $this->client->request(Request::METHOD_POST, '/account/connected-apps/preview-tokens/'.$id.'/revoke', ['_csrf_token' => 'invalid-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->isRevoked($id));
    }
}
