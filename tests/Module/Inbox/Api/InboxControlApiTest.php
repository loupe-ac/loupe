<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PublicSpec;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The list, withdrawal, action, command preview and action catalog routes of the inbox in the public API. */
final class InboxControlApiTest extends WebTestCase
{
    use InboxScenario;
    use WorkflowProjects;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private string $token;
    private int $number = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->project = $this->workflowProject('inbox-control');
        $this->verify($this->project->owner);
        $this->bindLifecycle($this->project);
        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->card = $createCard(new CreateCardCommand(project: $this->project, title: 'Add a tag filter', body: 'Body', type: 'feature', column: $this->column($this->project, 'in-progress'), reporter: Actor::Human));
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->project->owner);
        $this->setInboxFlag(true);
    }

    public function test_the_list_reads_newest_number_first_and_pages_by_cursor(): void
    {
        $items = array_map(fn (int $n): InboxItem => $this->todo($this->em, $this->project, $n, 'Item '.$n), [1, 2, 3, 4, 5]);
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?limit=2');

        self::assertResponseStatusCodeSame(200);
        $first = $this->json();
        self::assertSame([5, 4], array_column($first['items'], 'number'));
        self::assertNotNull($first['next']);
        self::assertStringContainsString('rel="next"', (string) $this->client->getResponse()->headers->get('Link'));
        PublicSpec::assertMatches('InboxItemList', $first);

        $this->send(Request::METHOD_GET, (string) $first['next']);
        $second = $this->json();
        self::assertSame([3, 2], array_column($second['items'], 'number'));

        $this->send(Request::METHOD_GET, (string) $second['next']);
        $third = $this->json();
        self::assertSame([1], array_column($third['items'], 'number'));
        self::assertNull($third['next']);
        self::assertSame((string) $items[0]->id, $third['items'][0]['itemId']);
    }

    public function test_the_list_filters_by_state_card_ask_and_session(): void
    {
        $open = $this->todo($this->em, $this->project, 1, 'Open one');
        $closed = $this->answered($this->em, $this->question($this->em, $this->project, 2));
        $linked = $this->todo($this->em, $this->project, 3, 'Linked');
        $linked->cards->add(new InboxItemCard($linked, $this->card));
        $ask = $this->askHolding($this->em, $this->project, [$open]);
        $this->em->flush();

        $this->send(Request::METHOD_GET, $this->base().'?state=open');
        self::assertSame([3, 1], array_column($this->json()['items'], 'number'));

        $this->send(Request::METHOD_GET, $this->base().'?state=resolved');
        self::assertSame([2], array_column($this->json()['items'], 'number'));

        $this->send(Request::METHOD_GET, $this->base().'?card='.$this->card->id);
        self::assertSame([3], array_column($this->json()['items'], 'number'));
        self::assertSame([$this->card->number], $this->json()['items'][0]['cardNumbers']);

        $this->send(Request::METHOD_GET, $this->base().'?ask='.$ask->id);
        self::assertSame([1], array_column($this->json()['items'], 'number'));

        $this->send(Request::METHOD_GET, $this->base().'?session='.$ask->sessionId);
        self::assertSame([1], array_column($this->json()['items'], 'number'));

        $this->send(Request::METHOD_GET, $this->base().'?session='.Uuid::v7());
        self::assertSame([], $this->json()['items']);
        self::assertNotNull($closed->id);
    }

    public function test_q_narrows_the_list_and_keeps_the_order_of_the_numbers(): void
    {
        foreach (['Rotate the staging certificate', 'Unrelated chore', 'Certificate expires on Friday, renew the certificate'] as $title) {
            $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => $title]);
            self::assertResponseStatusCodeSame(201);
        }

        $this->send(Request::METHOD_GET, $this->base().'?q=certificate');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([3, 1], array_column($this->json()['items'], 'number'));
    }

    public function test_a_walk_that_changes_a_filter_or_a_bad_filter_is_refused(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->todo($this->em, $this->project, $n);
        }
        $this->send(Request::METHOD_GET, $this->base().'?limit=1&state=open');
        $next = (string) $this->json()['next'];

        $this->send(Request::METHOD_GET, str_replace('state=open', 'state=resolved', $next));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_cursor', $this->json()['code']);

        foreach (['state=bogus' => 'state', 'card=nope' => 'card', 'q=%20' => 'q', 'limit=0' => 'limit'] as $query => $field) {
            $this->send(Request::METHOD_GET, $this->base().'?'.$query);
            self::assertResponseStatusCodeSame(422, $query);
            self::assertSame([$field], array_column($this->json()['errors'], 'field'), $query);
        }
    }

    public function test_the_list_holds_no_item_of_another_project(): void
    {
        $other = new Project($this->project->owner, 'inbox-control-other-'.uniqid());
        $this->em->persist($other);
        $this->em->flush();
        $this->todo($this->em, $other, 1, 'Elsewhere');
        $this->todo($this->em, $this->project, 1, 'Mine');

        $this->send(Request::METHOD_GET, $this->base());

        self::assertSame(['Mine'], array_column($this->json()['items'], 'title'));
    }

    public function test_a_withdrawal_cancels_an_open_ask_of_an_agent_and_names_the_key(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->askHolding($this->em, $this->project, [$item]);

        $this->send(Request::METHOD_POST, $this->base().'/'.$item->id.'/withdrawal', ['reason' => ' The pull request merged ']);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['cancelled', 'owner-through-api-key', 'withdrawn', 'The pull request merged'], [$body['state'], $body['closedBy'], $body['closeCause'], $body['note']]);
        PublicSpec::assertMatches('InboxItem', $body);

        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        self::assertSame([InboxCloser::OwnerThroughApiKey, InboxCloseCause::Withdrawn, 'Test key'], [$stored->closedBy, $stored->closeCause, $stored->closedApiKeyName]);
    }

    public function test_a_closed_item_and_an_item_no_caller_withdraws_are_conflicts(): void
    {
        $closed = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $notice = new InboxItem(project: $this->project, number: 2, kind: InboxItemKind::Notice, title: 'FYI', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($notice);
        $this->em->flush();

        $this->send(Request::METHOD_POST, $this->base().'/'.$closed->id.'/withdrawal', ['reason' => 'Settled']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('item_not_open', $this->json()['code']);

        $this->send(Request::METHOD_POST, $this->base().'/'.$notice->id.'/withdrawal', ['reason' => 'Settled']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('item_not_withdrawable', $this->json()['code']);
    }

    public function test_a_withdrawal_needs_a_reason_and_an_item_of_the_project(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $other = new Project($this->project->owner, 'inbox-control-foreign-'.uniqid());
        $this->em->persist($other);
        $this->em->flush();
        $foreign = $this->question($this->em, $other, 1);

        $this->send(Request::METHOD_POST, $this->base().'/'.$item->id.'/withdrawal', ['reason' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['field' => 'reason', 'code' => 'withdraw_reason_blank']], array_map(static fn (array $e): array => ['field' => $e['field'], 'code' => $e['code']], $this->json()['errors']));

        $this->send(Request::METHOD_POST, $this->base().'/'.$item->id.'/withdrawal', []);
        self::assertResponseStatusCodeSame(422);

        $this->send(Request::METHOD_POST, $this->base().'/'.$foreign->id.'/withdrawal', ['reason' => 'Settled']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('inbox_item_not_found', $this->json()['code']);
        self::assertSame(InboxItemState::Open, $this->reload($foreign)->state);
    }

    public function test_actions_answer_complete_and_dismiss_close_the_item_through_the_key(): void
    {
        $question = $this->question($this->em, $this->project, 1);
        $todo = $this->todo($this->em, $this->project, 2);
        $dismissed = $this->todo($this->em, $this->project, 3);

        $this->send(Request::METHOD_POST, $this->actionUrl($question), ['action' => 'answer', 'selectedOptions' => [1], 'note' => 'CSV, please']);
        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertNull($body['actionId']);
        self::assertSame(['resolved', 'owner-through-api-key', [1], 'CSV, please'], [$body['item']['state'], $body['item']['closedBy'], $body['item']['selectedOptions'], $body['item']['note']]);
        PublicSpec::assertMatches('InboxActionResult', $body);

        $this->send(Request::METHOD_POST, $this->actionUrl($todo), ['action' => 'complete', 'note' => 'Done']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('resolved', $this->json()['item']['state']);

        $this->send(Request::METHOD_POST, $this->actionUrl($dismissed), ['action' => 'dismiss']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('declined', $this->json()['item']['state']);

        foreach ([$question, $todo, $dismissed] as $item) {
            $stored = $this->reload($item);
            self::assertSame([InboxCloser::OwnerThroughApiKey, 'Test key'], [$stored->closedBy, $stored->closedApiKeyName]);
        }
    }

    public function test_an_action_on_a_closed_item_is_a_conflict_and_a_response_stays_final(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'answer', 'selectedOptions' => [0]]);
        self::assertResponseStatusCodeSame(200);

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'answer', 'selectedOptions' => [1]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('item_not_open', $this->json()['code']);
        self::assertSame([0], $this->reload($item)->selectedOptions);
    }

    public function test_an_action_that_does_not_fit_or_does_not_exist_is_refused(): void
    {
        $todo = $this->todo($this->em, $this->project, 1);

        $this->send(Request::METHOD_POST, $this->actionUrl($todo), ['action' => 'answer', 'selectedOptions' => [0]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);

        foreach (['open', 'review', 'launch'] as $action) {
            $this->send(Request::METHOD_POST, $this->actionUrl($todo), ['action' => $action]);
            self::assertResponseStatusCodeSame(422, $action);
        }
        $this->send(Request::METHOD_POST, $this->actionUrl($todo), []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Open, $this->reload($todo)->state);
    }

    public function test_an_action_of_an_item_of_another_project_is_not_found(): void
    {
        $other = new Project($this->project->owner, 'inbox-control-foreign-'.uniqid());
        $this->em->persist($other);
        $this->em->flush();
        $foreign = $this->todo($this->em, $other, 1);

        $this->send(Request::METHOD_POST, $this->base().'/'.$foreign->id.'/actions', ['action' => 'dismiss']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('inbox_item_not_found', $this->json()['code']);

        $this->send(Request::METHOD_GET, $this->base().'/'.$foreign->id.'/command');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(InboxItemState::Open, $this->reload($foreign)->state);
    }

    public function test_a_run_command_needs_the_digest_of_the_preview_and_runs_with_it(): void
    {
        $item = $this->commandTodo();

        $this->send(Request::METHOD_GET, $this->base().'/'.$item->id.'/command?branch=main&params[out]=other/folder');
        self::assertResponseStatusCodeSame(200);
        $preview = $this->json();
        self::assertSame(['design-export', 'main', ['bin/console', 'app:export', '{out}'], ['out' => 'other/folder']], [$preview['entry'], $preview['branch'], $preview['runList'], $preview['params']]);
        self::assertSame(['bin/console', 'app:export', 'other/folder'], $preview['argv']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $preview['runDigest']);
        self::assertNull($preview['disabledReason']);
        PublicSpec::assertMatches('InboxCommandPreview', $preview);
        self::assertSame(0, $this->workRequestCount());

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'run-command', 'branch' => 'main', 'params' => ['out' => 'other/folder']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_digest_required', $this->json()['errors'][0]['code']);

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'run-command', 'branch' => 'main', 'params' => ['out' => 'other/folder'], 'runDigest' => 'not-the-digest']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('run_digest_stale', $this->json()['code']);

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'run-command', 'branch' => 'main', 'params' => ['out' => 'other/folder', 'extra' => 'x'], 'runDigest' => $preview['runDigest']]);
        self::assertResponseStatusCodeSame(409, 'other params than the preview showed');
        self::assertSame(0, $this->workRequestCount());

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'run-command', 'branch' => 'main', 'params' => ['out' => 'other/folder'], 'runDigest' => $preview['runDigest']]);
        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->json()['actionId']);
        self::assertSame(1, $this->workRequestCount());
    }

    public function test_the_preview_of_a_closed_item_or_with_bad_query_values_is_refused(): void
    {
        $item = $this->commandTodo();

        $this->send(Request::METHOD_GET, $this->base().'/'.$item->id.'/command?params=nope');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['params'], array_column($this->json()['errors'], 'field'));

        $item->state = InboxItemState::Resolved;
        $this->em->flush();
        $this->send(Request::METHOD_GET, $this->base().'/'.$item->id.'/command');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('not_available', $this->json()['errors'][0]['code']);
    }

    public function test_the_action_catalog_lists_the_eight_actions_and_tells_which_fit_a_card(): void
    {
        $this->send(Request::METHOD_GET, $this->project_base().'/actions');
        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame(['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'run-command', 'dismiss'], array_column($body['actions'], 'action'));
        self::assertSame([null], array_values(array_unique(array_column($body['actions'], 'fitsCard'))));
        self::assertNotSame('', $body['actions'][0]['effect']);
        PublicSpec::assertMatches('InboxActionList', $body);

        $this->send(Request::METHOD_GET, $this->project_base().'/actions?card='.$this->card->id);
        self::assertResponseStatusCodeSame(200);
        foreach ($this->json()['actions'] as $row) {
            self::assertIsBool($row['fitsCard'], $row['action']);
        }

        $this->send(Request::METHOD_GET, $this->project_base().'/actions?card='.Uuid::v7());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['card'], array_column($this->json()['errors'], 'field'));

        $this->send(Request::METHOD_GET, $this->project_base().'/actions?card=nope');
        self::assertResponseStatusCodeSame(422);
    }

    /** @return iterable<string, array{array<string, mixed>, ?string}> */
    public static function sessionPrompts(): iterable
    {
        yield 'the prompt of the suggestion' => [[], 'Use the skill.'];
        yield 'a prompt the script sends' => [['sessionPrompt' => 'Read the plan first.'], 'Read the plan first.'];
        yield 'a prompt the script clears' => [['sessionPrompt' => ''], null];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('sessionPrompts')]
    public function test_a_session_starts_with_the_prompt_the_script_chose(array $extra, ?string $expected): void
    {
        $item = $this->sessionTodo();

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'start-session'] + $extra);

        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->json()['actionId']);
        self::assertSame($expected, $this->actionPrompt());
    }

    public function test_an_entry_is_added_for_the_asking_bridge(): void
    {
        $item = $this->commandTodo("action: command\nparams:\n  out: { pattern: '[a-z/]+' }\nrun: [make, export]");
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...$this->bridge->capabilities ?? [], Bridge::CAPABILITY_ADD_ENTRY];
        $this->em->flush();

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'add-entry', 'note' => 'Add it.']);

        self::assertResponseStatusCodeSame(200);
        self::assertNotNull($this->json()['actionId']);
        self::assertSame(1, $this->workRequestCount());
    }

    /** @return iterable<string, array{string}> */
    public static function actionsThatDoNotFitATodo(): iterable
    {
        foreach (['resume-run', 'retry-card', 'send-agent', 'start-session', 'add-entry'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actionsThatDoNotFitATodo')]
    public function test_an_action_that_the_item_does_not_offer_is_refused_with_a_coded_problem_and_changes_nothing(string $action): void
    {
        $item = $this->todo($this->em, $this->project, 1);

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => $action]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertSame('not_available', $this->json()['errors'][0]['code']);
        self::assertSame(0, $this->workRequestCount());
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_post_refused_while_the_inbox_is_off_spends_no_idempotency_key(): void
    {
        $server = ['HTTP_IDEMPOTENCY_KEY' => 'off-then-on'];
        $this->setInboxFlag(false);
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Once'], $server);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);

        $this->setInboxFlag(true);
        $this->send(Request::METHOD_POST, $this->base(), ['kind' => 'todo', 'title' => 'Once'], $server);

        self::assertResponseStatusCodeSame(201);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function routes(): iterable
    {
        yield 'list' => [Request::METHOD_GET, '/items', []];
        yield 'withdraw' => [Request::METHOD_POST, '/items/{id}/withdrawal', ['reason' => 'x']];
        yield 'action' => [Request::METHOD_POST, '/items/{id}/actions', ['action' => 'dismiss']];
        yield 'command' => [Request::METHOD_GET, '/items/{id}/command', []];
        yield 'actions' => [Request::METHOD_GET, '/actions', []];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('routes')]
    public function test_a_switched_off_inbox_answers_route_not_found_and_changes_nothing(string $method, string $suffix, array $payload): void
    {
        $item = $this->todo($this->em, $this->project, 1);
        $this->setInboxFlag(false);

        $this->send($method, $this->project_base().str_replace('{id}', (string) $item->id, $suffix), $payload);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('route_not_found', $this->json()['code']);
        self::assertFalse($this->client->getResponse()->headers->has('RateLimit-Limit'), 'the gate runs before the budget, so a refused request spends none');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('routes')]
    public function test_a_request_without_a_credential_is_refused(string $method, string $suffix, array $payload): void
    {
        $this->send($method, $this->project_base().str_replace('{id}', (string) Uuid::v7(), $suffix), $payload, token: null);

        self::assertResponseStatusCodeSame(401);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('routes')]
    public function test_a_project_of_another_owner_reads_as_a_missing_project(string $method, string $suffix, array $payload): void
    {
        $stranger = $this->signedUpUser($this->em, 'inbox-control-stranger');
        $item = $this->todo($this->em, $this->project, 1);

        $this->send($method, $this->project_base().str_replace('{id}', (string) $item->id, $suffix), $payload, token: ApiKeyCredential::mint(static::getContainer(), $stranger));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_cli_sign_in_runs_an_action_and_records_no_key(): void
    {
        $item = $this->todo($this->em, $this->project, 1);

        $this->send(Request::METHOD_POST, $this->actionUrl($item), ['action' => 'complete'], token: AgentCredential::tokenFor(static::getContainer(), $this->project->owner, 'public'));

        self::assertResponseStatusCodeSame(200);
        $stored = $this->reload($item);
        self::assertSame(InboxCloser::OwnerThroughApiKey, $stored->closedBy);
        self::assertNull($stored->closedApiKeyId);
    }

    private function verify(User $user): void
    {
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $this->em->flush();
    }

    private function commandTodo(?string $proposedEntry = null): InboxItem
    {
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        $this->bridge->projects = [(string) $this->project->id];
        $this->bridge->lastSeenAt = new \DateTimeImmutable();
        $this->bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => ['bin/console', 'app:export', '{out}'], 'timeout' => '10m']];
        $this->bridge->worktrees = [
            ['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe'],
            ['projectId' => (string) $this->project->id, 'branch' => 'epic/42', 'path' => '/work/loupe/.worktrees/card-42'],
        ];
        $this->em->persist($this->bridge);
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false, origin: InboxAskOrigin::Agent);
        $item->suggestedActions = [['action' => 'run-command', 'reason' => 'Export it.', 'recommended' => true, 'cardId' => null, 'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']], 'proposedEntry' => $proposedEntry]];
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->persist($item);
        $this->em->flush();
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $this->bridge->id);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->flush();

        return $item;
    }

    private function sessionTodo(): InboxItem
    {
        $bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $bridge->projects = [(string) $this->project->id];
        $this->em->persist($bridge);
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Rebuild it', blocking: false, origin: InboxAskOrigin::Agent);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $item->suggestedActions = [['action' => 'start-session', 'reason' => 'Talk it through.', 'recommended' => true, 'cardId' => (string) $this->card->id, 'prompt' => 'Use the skill.']];
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /** The prompt of the newest work request. */
    private function actionPrompt(): ?string
    {
        $prompt = $this->em->getConnection()->fetchOne('SELECT prompt FROM work_requests ORDER BY created_at DESC, id DESC LIMIT 1');

        return false === $prompt ? null : $prompt;
    }

    private function workRequestCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests');
    }

    private function reload(InboxItem $item): InboxItem
    {
        $id = $item->id;
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }

    private function project_base(): string
    {
        return '/api/v1/projects/'.$this->project->id.'/inbox';
    }

    private function base(): string
    {
        return $this->project_base().'/items';
    }

    private function actionUrl(InboxItem $item): string
    {
        return $this->base().'/'.$item->id.'/actions';
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server
     */
    private function send(string $method, string $url, array $payload = [], array $server = [], ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request(
            $method,
            $url,
            server: $server + ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]),
            content: Request::METHOD_GET === $method ? '' : json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
