<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class BoardCardsApiTest extends WebTestCase
{
    use BoardColumnFixtures;

    public function test_a_reviewer_creates_a_card_and_it_records_who_raised_it(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-create@example.com');

        $this->api($client, Request::METHOD_POST, '/api/v1/live-review-widget/cards', $raw, [
            'title' => 'Footer overlaps the launcher',
            'body' => 'Seen at 1280px.',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(1, $data['number']);
        self::assertSame('#1 Footer overlaps the launcher', $data['label']);
        self::assertStringContainsString($data['cardId'], $data['url']);

        $cards = static::getContainer()->get(CardRepository::class)->findBy(['project' => $project]);
        self::assertCount(1, $cards);
        // Not Human. Nobody authenticated the person who typed it.
        self::assertSame(Actor::Reviewer, $cards[0]->reporter);
        self::assertEquals(new CardSource(CardSourceKind::Widget), $cards[0]->source);
        // The endpoint accepts neither, so a reviewer cannot file into a column
        // or attach a URL of their choosing.
        self::assertSame('backlog', $cards[0]->column->slug);
        self::assertCount(0, $cards[0]->pullRequests);
    }

    public function test_the_widget_ignores_a_type_and_a_parent_and_makes_a_card_of_the_default_type(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->projectWithToken($client, 'cards-api-types@example.com');

        $this->api($client, Request::METHOD_POST, '/api/v1/live-review-widget/cards', $raw, ['title' => 'Review: /pricing']);
        self::assertResponseStatusCodeSame(201);
        $this->api($client, Request::METHOD_POST, '/api/v1/live-review-widget/cards', $raw, ['title' => 'Review: /checkout', 'type' => 'epic', 'parent' => true]);
        self::assertResponseStatusCodeSame(201);

        $cards = static::getContainer()->get(CardRepository::class)->findBy(['project' => $project], ['number' => 'ASC']);
        self::assertSame(['feature', 'feature'], array_map(static fn (Card $card) => $card->type, $cards));
        self::assertSame([Actor::Reviewer, Actor::Reviewer], array_map(static fn (Card $card) => $card->reporter, $cards));
    }

    public function test_an_account_token_cannot_reach_the_board(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('cards-api-mcp@example.com');
        $project = $scenario->createProject($user, 'cards-api-mcp');
        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        $this->api($client, Request::METHOD_POST, '/api/v1/live-review-widget/cards', $raw, ['title' => 'Review: /pricing']);

        // The access_control line names ROLE_API_SITE_REVIEW, so an MCP token
        // is refused by the firewall rather than by the controller.
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_widget_can_call_it_from_another_origin(): void
    {
        $client = static::createClient();
        [$raw] = $this->projectWithToken($client, 'cards-api-cors@example.com');

        $this->api($client, Request::METHOD_POST, '/api/v1/live-review-widget/cards', $raw, ['title' => 'Review: /pricing']);

        // The CORS subscriber tested one path prefix before this endpoint
        // existed, so without widening it the widget's own call would be
        // blocked by the browser with nothing in the server log.
        self::assertSame(
            'https://app.localhost',
            $client->getResponse()->headers->get('Access-Control-Allow-Origin'),
        );
    }

    /**
     * A project with a site-review credential of its owner. The helper takes
     * the credential last, because the authorization flow detaches every
     * entity the test holds before it.
     *
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project}
     */
    private function projectWithToken(KernelBrowser $client, string $email, string $name = 'cards-api'): array
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser($email);
        $project = $scenario->createProject($user, $name);
        $this->seedColumns($project);
        $this->em()->flush();
        $raw = $scenario->accessTokenFor($client, $user, 'site-review', $project);

        return [$raw, AgentCredential::managed($this->em(), $project, $project->id)];
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param array<string, mixed>|null $json */
    private function api(KernelBrowser $client, string $method, string $path, string $raw, ?array $json = null): void
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }
}
