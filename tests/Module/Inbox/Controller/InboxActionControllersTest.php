<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The run and cancel routes of an inbox action, and the thread that posts to them on the inbox page and the card page. */
final class InboxActionControllersTest extends WebTestCase
{
    use InboxScenario;
    use WorkflowProjects;

    private KernelBrowser $client;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private int $number = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->project = $this->workflowProject('inbox-thread');
        $owner = $this->project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $this->bindLifecycle($this->project);
        $this->card = $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Add a tag filter',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'in-progress'),
            reporter: Actor::Human,
        ));
        $this->bridge = new Bridge($owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($this->bridge);
        $this->em()->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_the_inbox_page_shows_the_message_the_reply_and_the_split_button(): void
    {
        $item = $this->runItem('Needs the **API key**. Add it to the bridge config.');

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $message = $crawler->filter('#inbox-item-'.$item->number.' [data-inbox-wait="current"]');
        self::assertCount(1, $message);
        self::assertSame('Implement worker', $message->filter('.lp-comment-author')->text());
        self::assertStringContainsString('stopped', $message->filter('.lp-form-hint')->text());
        self::assertSame('API key', $message->filter('.lp-review-doc__prose strong')->text());
        self::assertSame('Resume the run', trim($crawler->filter('[data-inbox-reply-heading]')->text()));
        $main = $crawler->filter('.inbox-split__main');
        self::assertSame('Resume the run', trim($main->text()));
        self::assertSame($this->actionPath($item, 'resume-run'), parse_url((string) $main->attr('formaction'), \PHP_URL_PATH));
        self::assertSame('Add a note (optional)', $crawler->filter('textarea')->attr('placeholder'));
        $menu = $crawler->filter('.inbox-split .lp-menu__panel');
        self::assertNotNull($menu->attr('hidden'));
        self::assertSame('menu', $menu->attr('role'));
        self::assertSame('false', $crawler->filter('.inbox-split__toggle')->attr('aria-expanded'));
        self::assertSame('panel', $menu->attr('data-split-button-target'));
        self::assertSame($menu->attr('id'), $crawler->filter('.inbox-split__toggle')->attr('aria-controls'));
        self::assertStringContainsString('split-button', (string) $crawler->filter('.inbox-split')->attr('data-controller'));
        // The menu holds the other actions, never the default, and Dismiss apart.
        self::assertCount(0, $menu->filter('[data-inbox-action="resume-run"]'));
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $menu->filter('a[data-inbox-action="open"]')->attr('href'));
        // Dismiss sits apart, after a divider.
        self::assertCount(1, $menu->filter('.lp-menu__divider'));
        $dismiss = $menu->filter('.lp-menu__divider ~ [data-inbox-action="dismiss"]');
        self::assertSame('inbox_decline_'.$item->id, $dismiss->attr('form'));
        self::assertSame('Dismiss', trim($dismiss->filter('.lp-menu__label')->text()));
        self::assertSame('Closes this item without an action.', $dismiss->filter('.lp-menu__line')->text());
    }

    public function test_a_resume_queues_the_command_and_the_thread_shows_the_you_card(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'resume-run'), $item, ['note' => 'Carry on.']);

        self::assertStringStartsWith($this->pageUrl().'?ask=', (string) $this->client->getResponse()->headers->get('Location'));
        $action = $this->onlyAction($item);
        self::assertSame(InboxActionKind::ResumeRun, $action->kind);
        self::assertSame('Carry on.', $action->note);
        self::assertSame(BridgeCommandState::Pending, $this->em()->find(BridgeCommand::class, $action->bridgeCommandId)?->state);

        $crawler = $this->client->followRedirect();
        $you = $crawler->filter('#inbox-item-'.$item->number.' [data-inbox-action-status="queued"]');
        self::assertCount(1, $you);
        self::assertSame('Carry on.', trim($you->filter('.lp-inbox-thread__note')->text()));
        self::assertStringContainsString('resumed the run at', $you->filter('.lp-comment-thread__header')->text());
        self::assertSame('Queued', trim($you->filter('.lp-status-chip')->text()));
        // While the action runs, the reply card is gone and Cancel takes its place.
        self::assertCount(0, $crawler->filter('#inbox-item-'.$item->number.' .inbox-split'));
        $cancel = $you->filter('form.lp-inbox-thread__cancel');
        self::assertSame($this->cancelPath($item, $action), parse_url((string) $cancel->attr('action'), \PHP_URL_PATH));
    }

    public function test_the_you_card_says_when_no_bridge_is_online(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl().'?item='.$item->number);

        self::assertSame('No bridge is online.', trim($crawler->filter('[data-inbox-action-offline]')->text()));
    }

    public function test_a_cancel_withdraws_the_queued_action_and_brings_the_reply_card_back(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, ['note' => 'Carry on.']);
        $action = $this->onlyAction($item);

        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);

        self::assertStringStartsWith($this->pageUrl().'?ask=', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        $stored = $this->em()->find(InboxAction::class, $action->id);
        self::assertNotNull($stored?->cancelledAt);
        self::assertSame(BridgeCommandState::Cancelled, $this->em()->find(BridgeCommand::class, $action->bridgeCommandId)?->state);
        $crawler = $this->client->followRedirect();
        self::assertSame('Cancelled', trim($crawler->filter('[data-inbox-action-status="cancelled"] .lp-status-chip')->text()));
        self::assertCount(1, $crawler->filter('#inbox-item-'.$item->number.' .inbox-split'));
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_cancel_of_an_action_that_is_not_pending_is_refused_in_place(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        $action = $this->onlyAction($item);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId) ?? throw new \LogicException('Stored.');
        $command->state = BridgeCommandState::Done;
        $this->em()->flush();

        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->onlyAction($item)->cancelledAt);
    }

    public function test_a_retry_releases_the_pause(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertSame('Retry the card', trim($crawler->filter('.inbox-split__main')->text()));

        $this->post($this->actionPath($item, 'retry-card'), $item, ['note' => 'Try again']);

        self::assertResponseRedirects();
        self::assertNull($this->service(CardPauses::class)->findActive($this->card->id ?? throw new \LogicException('Stored.')));
        self::assertSame(InboxActionKind::RetryCard, $this->onlyAction($item)->kind);
    }

    public function test_a_rule_pause_defaults_to_open_and_offers_no_retry(): void
    {
        $this->pauseItem(PauseKind::Rule);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('.inbox-split__main');
        self::assertSame('Open', trim($main->text()));
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $main->attr('href'));
        self::assertCount(0, $crawler->filter('[data-inbox-action="retry-card"]'));
    }

    public function test_a_dismiss_through_the_run_route_closes_the_item_with_the_note(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'dismiss'), $item, ['note' => 'Not now']);

        self::assertResponseRedirects();
        $stored = $this->reload($item);
        self::assertSame([InboxItemState::Declined, 'Not now'], [$stored->state, $stored->closeNote]);
    }

    public function test_the_menu_dismiss_posts_to_the_decline_route_and_ignores_the_note(): void
    {
        $item = $this->runItem();
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $form = $crawler->filter('form#inbox_decline_'.$item->id);
        self::assertCount(1, $form);
        self::assertSame('/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/decline', parse_url((string) $form->attr('action'), \PHP_URL_PATH));
        // Dismiss ignores the note, so the form carries none.
        self::assertNull($crawler->filter('textarea')->attr('data-inbox-decline-target'));
        self::assertSame('', (string) $form->filter('input[type="hidden"][name$="[closeNote]"]')->attr('value'));
        self::assertNull($crawler->filter('button[data-inbox-action="dismiss"]')->attr('data-action'));
    }

    public function test_a_note_over_the_limit_is_refused_and_keeps_the_typed_note(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'resume-run'), $item, ['note' => str_repeat('a', InboxAction::MAX_NOTE_LENGTH + 1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->actionsOf($item));
        self::assertSelectorExists('#inbox-item-'.$item->number.' .inbox-split');
        self::assertSelectorExists('#inbox-item-'.$item->number.' .lp-field-errors');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_action_that_does_not_fit_is_refused_in_place(): void
    {
        $item = $this->runItem(state: WorkerRunState::Running);

        $this->post($this->actionPath($item, 'resume-run'), $item, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-'.$item->number, 'This action no longer fits the item.');
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_a_forged_token_changes_nothing(): void
    {
        $item = $this->runItem();
        $url = $this->actionPath($item, 'resume-run');

        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['note' => 'x', '_token' => 'forged']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_a_stranger_cannot_run_or_cancel_an_action(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        $action = $this->onlyAction($item);
        $this->client->loginUser($this->signedUpUser($this->em(), 'inbox-stranger'));

        $this->post($this->actionPath($item, 'retry-card'), $item, []);
        self::assertResponseStatusCodeSame(403);
        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->onlyAction($item)->cancelledAt);
    }

    public function test_the_routes_answer_404_while_the_inbox_is_off_and_for_an_unknown_kind(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'review'), $item, []);
        self::assertResponseStatusCodeSame(404);

        $this->setInboxFlag(false);
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_an_item_of_another_project_is_not_found_under_this_one(): void
    {
        $item = $this->runItem();
        $other = $this->inboxProject($this->em(), $this->project->owner);

        $url = '/projects/'.$other->id.'/inbox/items/'.$item->id.'/actions/resume-run';
        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_card_page_shows_the_thread_posts_back_to_the_card_and_offers_no_link_to_itself(): void
    {
        $item = $this->runItem('Needs the API key.', linkCard: true);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        $thread = $crawler->filter('[data-inbox-linked="card"] [data-inbox-thread]');
        self::assertCount(1, $thread);
        self::assertSame('Resume the run', trim($thread->filter('.inbox-split__main')->text()));
        self::assertStringContainsString('returnTo=card', (string) $thread->filter('form.lp-inbox-thread__reply')->attr('action'));
        self::assertCount(0, $thread->filter('[data-inbox-action="open"]'));

        $url = $this->actionPath($item, 'resume-run').'?returnTo=card&returnId='.$this->card->id;
        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['note' => 'Go', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseRedirects($this->cardUrl().'?tab=conversation');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-inbox-linked="card"] [data-inbox-action-status="queued"]'));
    }

    public function test_the_card_page_starts_a_session_from_the_main_part(): void
    {
        $session = new Bridge($this->project->owner, Uuid::v4(), [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', new \DateTimeImmutable());
        $session->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->em()->persist($session);
        $this->em()->flush();
        $item = $this->runItem(state: WorkerRunState::Running, linkCard: true);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('[data-inbox-linked="card"] .inbox-split__main');
        self::assertSame('start-session', $main->attr('data-inbox-action'));
        self::assertSame($this->actionPath($item, 'start-session'), parse_url((string) $main->attr('formaction'), \PHP_URL_PATH));

        $url = $this->actionPath($item, 'start-session').'?returnTo=card&returnId='.$this->card->id;
        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['note' => 'Pair on it', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseRedirects($this->cardUrl().'?tab=conversation');
        self::assertSame(InboxActionKind::StartSession, $this->onlyAction($item)->kind);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The session of item '.$item->number.' is queued.', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-inbox-linked="card"] [data-inbox-action-status="queued"]'));
        // The session answers the open wait, so the queued You card replaces the reply.
        self::assertNotNull($this->onlyAction($item)->waitId);
        self::assertCount(0, $crawler->filter('[data-inbox-linked="card"] .inbox-split'));
    }

    public function test_a_question_keeps_its_answer_form_with_the_heading_your_answer(): void
    {
        $question = $this->question($this->em(), $this->project, 40);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Your answer', trim($crawler->filter('#inbox-item-'.$question->number.' legend.lp-decision__prompt')->text()));
        self::assertCount(0, $crawler->filter('#inbox-item-'.$question->number.' [data-inbox-thread]'));
    }

    private function runItem(string $report = 'Needs the API key.', WorkerRunState $state = WorkerRunState::Blocked, bool $linkCard = false): InboxItem
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            output: $report,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: $run->id), $linkCard);
    }

    private function pauseItem(PauseKind $kind): InboxItem
    {
        $pause = $this->service(PauseCardHandler::class)(new PauseCardCommand($this->card, 'on-hold', 'review-rule', $kind))
            ?? throw new \LogicException('The card had no pause.');

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::forPause($kind), pauseId: $pause->id));
    }

    /** @param \Closure(InboxCardWatch): InboxCardWait $wait */
    private function itemWithWait(\Closure $wait, bool $linkCard = false): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number);
        $this->em()->persist($watch);
        $watch->waits->add($wait($watch));
        if ($linkCard) {
            $item->cards->add(new InboxItemCard($item, $this->card));
        }
        $this->em()->flush();
        $this->askHolding($this->em(), $this->project, [$item]);

        return $item;
    }

    /** @param array<string, string> $fields */
    private function post(string $url, InboxItem $item, array $fields, ?string $formName = null): Crawler
    {
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a same-origin Referer lets stand in for a signed token.
        return $this->client->request(Request::METHOD_POST, $url, [($formName ?? 'inbox_action_'.$item->id) => [...$fields, '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function actionPath(InboxItem $item, string $kind): string
    {
        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/'.$kind;
    }

    private function cancelPath(InboxItem $item, InboxAction $action): string
    {
        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/'.$action->id.'/cancel';
    }

    /** @return list<InboxAction> */
    private function actionsOf(InboxItem $item): array
    {
        $this->em()->clear();

        $stored = $this->em()->find(InboxItem::class, $item->id) ?? throw new \LogicException('Stored.');

        return $this->service(InboxActionRepository::class)->findByItem($stored);
    }

    private function onlyAction(InboxItem $item): InboxAction
    {
        $actions = $this->actionsOf($item);
        self::assertCount(1, $actions);

        return $actions[0];
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em()->clear();
        $stored = $this->em()->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }

    private function cardUrl(): string
    {
        return '/projects/'.$this->project->id.'/board/cards/'.$this->card->id;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
