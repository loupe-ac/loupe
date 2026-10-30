<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardValue;
use App\Module\Board\Form\SetCardValueFormType;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class SetCardValueControllerTest extends WebTestCase
{
    use WorkflowProjects;

    private const string KEY = 'tech-design-interactive';

    public function test_the_details_tab_shows_the_values_group_with_an_unset_row(): void
    {
        [$client, $project, $card] = $this->scenario('values-show');
        $this->em()->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('#card-panel-details [data-card-values]');
        self::assertCount(1, $group);
        self::assertSame('Values', trim($group->filter('[data-card-values-heading]')->text()));
        $row = $group->filter('#card-value-'.self::KEY);
        self::assertCount(1, $row);
        self::assertSame('Tech design', trim($row->filter('.lp-card-value__label')->text()));
        $switch = $row->filter('input.lp-switch[role="switch"]');
        self::assertCount(1, $switch);
        self::assertNull($switch->attr('checked'));
        self::assertNull($switch->attr('disabled'));
        self::assertSame('Interactive session', trim($row->filter('label')->text()));
        self::assertSame('submit-on-change', $row->filter('form')->attr('data-controller'));
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id.'/values/'.self::KEY, $row->filter('form')->attr('action'));
        self::assertStringContainsString('Turn this on to write the tech design in a session with you.', $row->filter('[role="tooltip"]')->text());
        self::assertCount(1, $row->filter('[role="tooltip"]#'.$switch->attr('aria-describedby')));
        self::assertSame('Not set', trim($row->filter('.lp-card-value__meta')->text()));
    }

    public function test_the_row_shows_the_stored_state_and_who_set_it(): void
    {
        [$client, $project, $card] = $this->scenario('values-stored');
        $this->em()->persist(new CardValue($card, self::KEY, true, Actor::Human, $project->owner));
        $this->em()->flush();
        $this->em()->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        $row = $crawler->filter('#card-value-'.self::KEY);
        self::assertNotNull($row->filter('input.lp-switch')->attr('checked'));
        self::assertStringStartsWith('Set by Riley', trim($row->filter('.lp-card-value__meta')->text()));
    }

    public function test_a_project_whose_template_declares_no_key_shows_no_values_group(): void
    {
        $client = static::createClient();
        $project = $this->workflowProject('values-none');
        $this->signIn($client, $project->owner);
        $card = $this->card($project);
        $this->em()->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        // Guard: the Details tab rendered, so the absence below is not a page that failed to draw.
        self::assertCount(1, $crawler->filter('#card-panel-details .lp-card-fields'));
        self::assertCount(0, $crawler->filter('[data-card-values]'));
        self::assertStringNotContainsString('Values', $crawler->filter('#card-panel-details')->text());
    }

    public function test_it_turns_the_value_on_and_off_and_redirects_to_the_details_tab(): void
    {
        [$client, $project, $card] = $this->scenario('values-set');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $this->post($client, (string) $project->id, $cardId, self::KEY, true);
        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId.'?tab=details');
        $row = $this->storedRow($cardId);
        self::assertTrue($row->value);
        self::assertSame(Actor::Human, $row->writerKind);
        self::assertSame(1, $this->events($cardId));

        $this->post($client, (string) $project->id, $cardId, self::KEY, false);
        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId.'?tab=details');
        self::assertFalse($this->storedRow($cardId)->value);
        self::assertSame(2, $this->events($cardId));
    }

    public function test_a_stale_submit_of_the_same_state_changes_nothing(): void
    {
        [$client, $project, $card] = $this->scenario('values-stale');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $this->post($client, (string) $project->id, $cardId, self::KEY, true);
        $this->post($client, (string) $project->id, $cardId, self::KEY, true);

        self::assertResponseRedirects();
        self::assertTrue($this->storedRow($cardId)->value);
        self::assertSame(1, $this->events($cardId));
    }

    public function test_a_turbo_request_gets_a_stream_that_replaces_the_row(): void
    {
        [$client, $project, $card] = $this->scenario('values-stream');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $this->post($client, (string) $project->id, $cardId, self::KEY, true, TurboBundle::STREAM_MEDIA_TYPE);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="card-value-'.self::KEY.'">', $content);
        self::assertMatchesRegularExpression('/<input[^>]*lp-switch[^>]*checked/', $content);
        self::assertStringContainsString('Set by Riley', $content);
        self::assertTrue($this->storedRow($cardId)->value);
    }

    public function test_a_form_with_a_bad_token_is_refused_and_changes_nothing(): void
    {
        [$client, $project, $card] = $this->scenario('values-badtoken');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/values/'.self::KEY,
            [SetCardValueFormType::nameFor($cardId, self::KEY) => ['value' => '1', '_token' => 'wrong']],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$project->id.'/board/cards/'.$cardId, 'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('was not saved', (string) $client->getResponse()->getContent());
        self::assertNull($this->findRow($cardId));
    }

    public function test_a_bad_token_without_turbo_flashes_and_redirects(): void
    {
        [$client, $project, $card] = $this->scenario('values-badtoken-plain');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/values/'.self::KEY,
            [SetCardValueFormType::nameFor($cardId, self::KEY) => ['value' => '1', '_token' => 'wrong']],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$project->id.'/board/cards/'.$cardId],
        );

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$cardId.'?tab=details');
        self::assertNull($this->findRow($cardId));
    }

    public function test_a_key_the_template_does_not_declare_is_not_found(): void
    {
        [$client, $project, $card] = $this->scenario('values-undeclared');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $this->post($client, (string) $project->id, $cardId, 'no-such-key', true);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->findRow($cardId));
        self::assertSame(0, $this->events($cardId));
    }

    public function test_a_key_is_not_found_when_the_template_declares_none(): void
    {
        $client = static::createClient();
        $project = $this->workflowProject('values-undeclared-none');
        $this->signIn($client, $project->owner);
        $card = $this->card($project);
        $cardId = (string) $card->id;
        $this->em()->clear();

        $this->post($client, (string) $project->id, $cardId, self::KEY, true);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->findRow($cardId));
    }

    public function test_a_user_who_cannot_write_the_card_is_forbidden(): void
    {
        [$client, $project, $card] = $this->scenario('values-owner');
        $stranger = new User(fullName: 'Sam Stranger', email: 'values-stranger-'.uniqid().'@example.com', password: 'x');
        $stranger->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($stranger, static::getContainer());
        $this->em()->persist($stranger);
        $this->em()->flush();
        $cardId = (string) $card->id;
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, (string) $project->id, $cardId, self::KEY, true);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->findRow($cardId));
    }

    public function test_the_history_tab_tells_the_change_with_the_declared_phrase(): void
    {
        [$client, $project, $card] = $this->scenario('values-history');
        $cardId = (string) $card->id;
        $this->em()->clear();

        $this->post($client, (string) $project->id, $cardId, self::KEY, true);
        $this->post($client, (string) $project->id, $cardId, self::KEY, false);
        $this->em()->clear();
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);

        $text = $crawler->filter('#card-panel-history')->text();
        self::assertStringContainsString('Riley turned on the interactive session for tech design.', $text);
        self::assertStringContainsString('Riley turned off the interactive session for tech design.', $text);
    }

    /** @return array{KernelBrowser, Project, Card} */
    private function scenario(string $name): array
    {
        $client = static::createClient();
        $project = $this->workflowProject($name);
        $this->bindLifecycle($project);
        $this->signIn($client, $project->owner);

        return [$client, $project, $this->card($project)];
    }

    private function signIn(KernelBrowser $client, User $owner): void
    {
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $this->em()->flush();
        $client->loginUser($owner);
    }

    private function card(Project $project): Card
    {
        $create = static::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $project,
            title: 'A card',
            body: '',
            type: 'feature',
            column: $this->column($project, 'backlog'),
            reporter: Actor::Human,
        ));
    }

    private function post(KernelBrowser $client, string $projectId, string $cardId, string $key, bool $value, ?string $accept = null): void
    {
        $fields = ['_token' => 'csrf-token'];
        if ($value) {
            $fields['value'] = '1';
        }
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$projectId.'/board/cards/'.$cardId.'/values/'.$key,
            [SetCardValueFormType::nameFor($cardId, $key) => $fields],
            server: ['HTTP_REFERER' => 'http://localhost/projects/'.$projectId.'/board/cards/'.$cardId] + (null === $accept ? [] : ['HTTP_ACCEPT' => $accept]),
        );
    }

    private function findRow(string $cardId): ?CardValue
    {
        $this->em()->clear();
        $card = $this->em()->find(Card::class, $cardId) ?? throw new \LogicException('The card is gone.');
        $values = static::getContainer()->get(CardValueRepository::class);
        self::assertInstanceOf(CardValueRepository::class, $values);

        return $values->findOneForCardFresh($card, self::KEY);
    }

    private function storedRow(string $cardId): CardValue
    {
        return $this->findRow($cardId) ?? self::fail('The card holds no value.');
    }

    private function events(string $cardId): int
    {
        $events = static::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);

        return $events->count(['card' => $cardId, 'kind' => CardEventKind::ValueChanged]);
    }
}
