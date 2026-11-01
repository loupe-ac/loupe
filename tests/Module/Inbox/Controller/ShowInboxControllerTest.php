<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Mercure\LiveUpdates;
use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShowInboxControllerTest extends WebTestCase
{
    use InboxScenario;
    use BoardColumnFixtures;
    use MercureCookies;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_page_answers_404_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_sidebar_hides_the_entry_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-nav-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/projects/'.$project->id.'/inbox"]');
    }

    public function test_a_stranger_is_refused(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-owner'));
        $this->setInboxFlag(true);

        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-stranger'));
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-empty');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-empty-state', 'Nothing needs you');
        self::assertSelectorExists('[data-inbox-queue-empty="open-asks"] .lp-empty-state__icon');
        self::assertSelectorNotExists('[data-inbox-queue-empty="open-asks"] a');
        self::assertSelectorNotExists('[data-inbox-open-count]');
        // The frame stays, so a live reload has somewhere to put a count.
        self::assertSelectorExists('a[data-controller="live-pill"] turbo-frame#inbox-open-count-'.$project->id);
    }

    public function test_linked_card_pull_requests_appear_once_with_safe_links(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pull-requests');
        $project = $this->inboxProject($this->em, $owner);
        $this->seedColumns($project);
        $item = $this->question($this->em, $project, 1);
        foreach ([1, 2] as $number) {
            $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Linked work', body: 'Body', number: $number);
            $this->em->persist($card);
            $this->em->persist(new CardPullRequest($card, 'https://github.com/example/app/pull/42', repository: 'example/app', number: 42));
            $this->em->persist(new CardPullRequest($card, 'javascript:alert(1)'));
            $item->cards->add(new InboxItemCard($item, $card));
        }
        $this->em->flush();
        $this->setInboxFlag(true);
        $projectId = (string) $project->id;
        $this->em->clear();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$projectId.'/inbox');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-linked-pull-request]');
        self::assertCount(2, $rows);
        self::assertStringContainsString('example/app #42', $rows->text());
        self::assertSame('Pull request', $rows->first()->filter('.lp-menu__line')->text());
        self::assertSame('a', $rows->first()->nodeName());
        self::assertSame('https://github.com/example/app/pull/42', $rows->first()->attr('href'));
        self::assertSame('_blank', $rows->first()->attr('target'));
        self::assertSame('noopener noreferrer', $rows->first()->attr('rel'));
        self::assertStringContainsString('Not a web address', $rows->last()->text());
        self::assertSame('button', $rows->last()->nodeName());
        self::assertNotNull($rows->last()->attr('disabled'));
        self::assertNull($rows->last()->attr('href'));
        self::assertCount(1, $crawler->filter('.lp-inbox-ask__byline > .lp-inbox-ask__byline-actions > [data-inbox-linked-menu]'));
    }

    public function test_the_linked_menu_shows_three_rows_of_a_kind_and_holds_the_rest_behind_show_more(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-linked-more');
        $project = $this->inboxProject($this->em, $owner);
        $this->seedColumns($project);
        $item = $this->question($this->em, $project, 1);
        foreach ([1, 2, 3, 4] as $number) {
            $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Linked work '.$number, body: 'Body', number: $number);
            $this->em->persist($card);
            $item->cards->add(new InboxItemCard($item, $card));
        }
        $this->em->flush();
        $this->setInboxFlag(true);
        $projectId = (string) $project->id;
        $this->em->clear();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$projectId.'/inbox');

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('[data-inbox-linked-menu] [data-linked-group="card"]');
        self::assertCount(1, $group);
        self::assertSame('Linked cards (4)', trim($group->filter('.lp-menu__heading')->text()));
        self::assertCount(3, $group->children('[data-linked-card]'));
        self::assertSame('Show 1 more', trim($group->filter('.lp-menu__more-toggle')->text()));
        self::assertCount(1, $group->filter('.lp-menu__more [data-linked-card]'));
        self::assertCount(4, $group->filter('[data-linked-card]'));
    }

    public function test_the_pill_listens_on_the_owner_inbox_topic(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-topic');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);
        $inboxTopic = $topics->forInbox($owner->id ?? throw new \LogicException('The owner has no id.'));
        $projectTopics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $projectTopics);
        $topics = [$inboxTopic, $projectTopics->forWorkerRuns($project->id ?? throw new \LogicException('The project has no id.'))];
        self::assertSame($topics, self::subscribedTopics($this->client->getResponse()));
        self::assertSame($topics, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn ($input): ?string => $input->attr('value')));

        $link = $crawler->filter('a[data-live-pill-events-value=\'["inbox.open_count_changed"]\']');
        self::assertCount(1, $link);
        self::assertSame((string) $project->id, $link->attr('data-live-pill-project-value'));
        self::assertSame('/projects/'.$project->id.'/inbox/open-count', $link->attr('data-live-pill-url-value'));
        self::assertSame('1', $link->filter('turbo-frame[data-live-pill-target="frame"] [data-inbox-open-count]')->text());
    }

    public function test_the_page_listens_on_the_project_inbox_topic_for_live_changes(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-page-topic');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $pageTopic = $topics->forInbox($project->id ?? throw new \LogicException('The project has no id.'));
        self::assertContains($pageTopic, self::subscribedTopics($this->client->getResponse()) ?? []);
        self::assertContains($pageTopic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn ($input): ?string => $input->attr('value')));
        self::assertCount(1, $crawler->filter('[data-controller~="inbox-live"]'));
        // A loose item has no ask, so its pane names the item for the live refresh.
        self::assertCount(1, $crawler->filter('[data-inbox-item-number="1"]'));
    }

    public function test_a_live_refresh_says_why_the_watched_ask_closed(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-live-closed');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->question($this->em, $project, 1);
        $item->state = InboxItemState::Cancelled;
        $item->closedBy = InboxCloser::Agent;
        $item->closeCause = InboxCloseCause::Withdrawn;
        $ask = $this->askHolding($this->em, $project, [$item], closedAt: new \DateTimeImmutable('-1 minute'));
        $this->setInboxFlag(true);
        $uri = '/projects/'.$project->id.'/inbox?ask='.$ask->id;
        $refresh = ['HTTP_X_LOUPE_LIVE_REFRESH' => '1', 'HTTP_TURBO_FRAME' => 'inbox-view'];

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, $uri, server: $refresh + ['HTTP_X_LOUPE_INBOX_WATCHING' => (string) $ask->id]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-inbox-closed-notice] a[data-turbo-frame="inbox-view"]');
        self::assertSelectorTextContains('[data-inbox-closed-notice]', 'Withdrawn by the agent.');

        // Guard: the notice answers the watch, not the closed ask. A visit of that ask from the Closed side says nothing.
        $this->client->request(Request::METHOD_GET, $uri, server: $refresh);
        self::assertSelectorNotExists('[data-inbox-closed-notice]');
        $this->client->request(Request::METHOD_GET, $uri);
        self::assertSelectorNotExists('[data-inbox-closed-notice]');
    }

    public function test_the_closed_notice_reads_the_reason_of_the_item_that_closed_the_ask(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-live-closing');
        $project = $this->inboxProject($this->em, $owner);
        $earlier = $this->answered($this->em, $this->question($this->em, $project, 1));
        $earlier->note = 'An earlier note.';
        $closing = $this->question($this->em, $project, 2);
        $closing->state = InboxItemState::Cancelled;
        $closing->closedBy = InboxCloser::Agent;
        $closing->closeCause = InboxCloseCause::Withdrawn;
        $closing->closedAt = new \DateTimeImmutable('-1 minute');
        $closing->note = 'The plan changed.';
        $ask = $this->askHolding($this->em, $project, [$earlier, $closing], closedAt: new \DateTimeImmutable('-1 minute'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$ask->id, server: [
            'HTTP_X_LOUPE_LIVE_REFRESH' => '1',
            'HTTP_TURBO_FRAME' => 'inbox-view',
            'HTTP_X_LOUPE_INBOX_WATCHING' => (string) $ask->id,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-inbox-closed-notice]', 'Withdrawn by the agent. The plan changed.');
        self::assertSelectorTextNotContains('[data-inbox-closed-notice]', 'An earlier note.');
    }

    public function test_the_open_count_frame_renders_the_pill_alone(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-frame');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->question($this->em, $project, 2);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $crawler->filter('turbo-frame#inbox-open-count-'.$project->id));
        self::assertSelectorTextSame('[data-inbox-open-count]', '2');
        self::assertSelectorNotExists('nav');
    }

    public function test_the_open_count_frame_is_refused_to_a_stranger_and_absent_while_the_inbox_is_off(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-pill-frame-owner'));
        $this->setInboxFlag(true);

        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-pill-frame-stranger'));
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($project->owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');
        self::assertResponseIsSuccessful();

        $this->setInboxFlag(false);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_with_live_updates_off_the_pill_renders_its_count_and_nothing_subscribes(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-no-live');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->question($this->em, $project, 2);
        $this->setInboxFlag(true);
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[LiveUpdates::FLAG]->value = false;
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('a[data-controller="live-pill"] [data-inbox-open-count]', '2');
        self::assertNull(self::subscribedTopics($this->client->getResponse()));
        self::assertSelectorNotExists('form#mercure-subscriptions');
    }

    public function test_no_inbox_topic_is_granted_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        $projectTopics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $projectTopics);
        self::assertSame(
            [$projectTopics->forWorkerRuns($project->id ?? throw new \LogicException('The project has no id.'))],
            self::subscribedTopics($this->client->getResponse()),
        );
    }

    public function test_a_one_item_ask_names_its_item_once_as_the_heading(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-one-item');
        $project = $this->inboxProject($this->em, $owner);
        $ask = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 3, ['Yes', 'No'], title: 'Ship on Friday?')], context: 'The release notes are ready.');
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        $block = $crawler->filter('[data-inbox-ask-id="'.$ask->id.'"]');
        self::assertSame('Ship on Friday?', $block->filter('h2.lp-inbox-ask__title')->text());
        self::assertCount(0, $block->filter('.lp-inbox-item__title'));
        self::assertStringContainsString('The release notes are ready.', $block->filter('.lp-inbox-ask__context')->text());
    }

    public function test_an_ask_shows_its_session_its_sanitized_context_and_its_numbered_items(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-render');
        $project = $this->inboxProject($this->em, $owner);
        $question = $this->question($this->em, $project, 12, ['JSON', 'CSV'], freeText: true, title: 'Which format?');
        $todo = $this->todo($this->em, $project, 13);
        $ask = $this->askHolding($this->em, $project, [$question, $todo], context: "Working on **the export**.\n\n<script>alert('x')</script>");
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-inbox-ask-id="'.$ask->id.'"]');
        self::assertCount(1, $block);
        // The ask reads as one request: its heading, then the agent's note, then its items.
        self::assertSame('Which format?', $block->filter('.lp-inbox-ask__title')->text());
        self::assertCount(0, $block->filter('.lp-inbox-ask__session'));
        self::assertStringContainsString((string) $ask->sessionId, $block->filter('.lp-presence [role="tooltip"]')->text());
        self::assertSame('the export', $block->filter('.lp-inbox-ask__context strong')->text());
        // The byline names the kind of the leading item, never a count of the items.
        self::assertSame('Question', $block->filter('[data-inbox-kind-label]')->text());
        // A missing translation renders its key, and the gate does not fail on that.
        self::assertDoesNotMatchRegularExpression('/\\binbox\\.[a-z_]+\\.[a-z_.]+/', $crawler->filter('main')->text());
        // Dismiss sits in the action row, posts the answer form to the dismiss address, and so carries the note field's text.
        self::assertCount(1, $block->filter('#inbox-item-12 .lp-comment-composer__actions > button[formaction*="/actions/dismiss"]'));
        self::assertCount(0, $block->filter('#inbox-item-12 .lp-comment-composer__actions > button[form]'));
        self::assertCount(1, $block->filter('form#inbox_response_'.$question->id.'[action*="/actions/answer"]'));
        self::assertCount(0, $block->filter('[name$="[closeNote]"]'));
        self::assertCount(0, $block->filter('script'));
        self::assertSame('1', $block->filter('#inbox-item-12 .lp-inbox-item__number')->text());
        self::assertSame('2', $block->filter('#inbox-item-13 .lp-inbox-item__number')->text());
        self::assertCount(2, $block->filter('#inbox-item-12 input[data-form-draft-target="option"]'));
        self::assertCount(1, $block->filter('#inbox-item-12 textarea[name="inbox_response_'.$question->id.'[note]"]'));
        self::assertCount(1, $block->filter('#inbox-item-13 form[name="inbox_response_'.$todo->id.'"]'));
        self::assertSelectorTextSame('[data-inbox-open-count]', '2');
        self::assertSelectorTextSame('a[href="/projects/'.$project->id.'/inbox"] .sr-only', '2 open items');
    }

    public function test_the_page_is_one_frame_with_a_row_link_per_ask_and_the_selected_ask(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-redesign');
        $project = $this->inboxProject($this->em, $owner);
        $question = $this->question($this->em, $project, 1);
        $todo = $this->todo($this->em, $project, 3);
        $first = $this->askHolding($this->em, $project, [$question, $todo], createdAt: new \DateTimeImmutable('-2 hours'));
        $second = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#inbox-view[data-turbo-action="advance"]');
        self::assertCount(1, $frame);
        self::assertSame(['Open · 2', 'Closed'], $frame->filter('.lp-tabs__tab')->each(static fn ($node): string => $node->text()));
        self::assertSame('page', $frame->filter('.lp-tabs__tab[data-inbox-side="open"]')->attr('aria-current'));
        self::assertCount(1, $crawler->filter('[data-inbox-section="open-asks"]'));
        // One link per ask, not per item, and the first row opens.
        $rows = $frame->filter('a.lp-list-row');
        self::assertSame(
            ['/projects/'.$project->id.'/inbox?ask='.$first->id, '/projects/'.$project->id.'/inbox?ask='.$second->id],
            $rows->each(static fn ($node): string => (string) $node->attr('href')),
        );
        self::assertSame('true', $rows->first()->attr('aria-current'));
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id]'));
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$first->id.'"]'));
        self::assertSame('From the agent', $crawler->filter('[data-inbox-ask-id] .lp-inbox-ask__source')->text());
        // The old tab machinery is gone from this page.
        self::assertCount(0, $crawler->filter('[data-controller="panel-tabs"], [role="tab"], [role="tabpanel"]'));
        self::assertSame('false', $crawler->filter('.lp-inbox-split')->attr('data-inbox-picked'));
    }

    public function test_an_ask_in_the_address_is_the_one_shown_and_marks_the_view_picked(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pick');
        $project = $this->inboxProject($this->em, $owner);
        $first = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)], createdAt: new \DateTimeImmutable('-2 hours'));
        $second = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$second->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$second->id.'"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-ask-id="'.$first->id.'"]'));
        self::assertSame('true', $crawler->filter('.lp-inbox-split')->attr('data-inbox-picked'));
        self::assertSame('true', $crawler->filter('a.lp-list-row[href$="ask='.$second->id.'"]')->attr('aria-current'));
        self::assertStringEndsWith('/projects/'.$project->id.'/inbox', (string) $crawler->filter('.lp-inbox-back')->attr('href'));
    }

    public function test_an_ask_of_another_project_selects_nothing(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-foreign-ask');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)]);
        $other = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-foreign-owner'));
        $foreign = $this->askHolding($this->em, $other, [$this->question($this->em, $other, 1, title: 'Secret question')]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$foreign->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-inbox-ask-id]'));
        self::assertSelectorExists('[data-inbox-pane-missing]');
        self::assertStringNotContainsString('Secret question', (string) $crawler->text());
    }

    public function test_an_ask_the_list_does_not_hold_still_shows(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-unlisted-ask');
        $project = $this->inboxProject($this->em, $owner);
        $open = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)]);
        $closed = $this->askHolding($this->em, $project, [$this->answered($this->em, $this->question($this->em, $project, 2))], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$closed->id);

        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$closed->id.'"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-ask-id="'.$open->id.'"]'));
        self::assertSame([(string) $open->id], $crawler->filter('a.lp-list-row')->each(static fn ($node): string => (string) $node->attr('data-inbox-request')));
        self::assertCount(0, $crawler->filter('a.lp-list-row[aria-current]'));
    }

    public function test_a_bad_address_falls_back_to_the_default_view(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-bad-address');
        $project = $this->inboxProject($this->em, $owner);
        $ask = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=x&type=y&ask=z&item=-1&page=0&q[]=a');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$ask->id.'"]'));
        self::assertSame('false', $crawler->filter('.lp-inbox-split')->attr('data-inbox-picked'));
    }

    public function test_an_item_number_opens_the_open_ask_first_then_the_newest_closed_ask_then_the_loose_row(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-item-link');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1, title: 'Unrelated')], createdAt: new \DateTimeImmutable('-3 hours'));
        $shared = $this->todo($this->em, $project, 2, 'Shared to-do');
        $olderClosed = $this->askHolding($this->em, $project, [$shared], closedAt: new \DateTimeImmutable('-5 hours'));
        $newerClosed = $this->askHolding($this->em, $project, [$shared], closedAt: new \DateTimeImmutable('-2 hours'));
        $openHolder = $this->askHolding($this->em, $project, [$this->todo($this->em, $project, 3, 'In an open ask')]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $url = '/projects/'.$project->id.'/inbox?item=';

        $crawler = $this->client->request(Request::METHOD_GET, $url.'3');
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$openHolder->id.'"]'));

        $crawler = $this->client->request(Request::METHOD_GET, $url.'2');
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$newerClosed->id.'"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-ask-id="'.$olderClosed->id.'"]'));

        $crawler = $this->client->request(Request::METHOD_GET, $url.'99');
        self::assertCount(0, $crawler->filter('[data-inbox-ask-id]'));
        self::assertSelectorExists('[data-inbox-pane-missing]');
    }

    public function test_a_loose_item_is_a_row_of_its_own_and_its_link_names_the_item(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-loose-row');
        $project = $this->inboxProject($this->em, $owner);
        $loose = $this->todo($this->em, $project, 7, 'Left open');
        $this->askHolding($this->em, $project, [$loose], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        $row = $crawler->filter('a.lp-list-row');
        self::assertCount(1, $row);
        self::assertSame('/projects/'.$project->id.'/inbox?item=7', $row->attr('href'));
        self::assertSame('todo', $row->filter('[data-inbox-kind]')->attr('data-inbox-kind'));
        self::assertCount(1, $crawler->filter('#inbox-item-7 form[name="inbox_response_'.$loose->id.'"]'));
    }

    public function test_the_open_side_lists_blocking_asks_first_then_the_others_oldest_first(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-blocking-first');
        $project = $this->inboxProject($this->em, $owner);
        $oldQuiet = $this->todo($this->em, $project, 1, 'Quiet and old');
        $newBlocking = $this->question($this->em, $project, 2, title: 'Blocking and new');
        $newQuiet = $this->todo($this->em, $project, 3, 'Quiet and new');
        $oldBlocking = $this->question($this->em, $project, 4, title: 'Blocking and old');
        $asks = [
            $this->askHolding($this->em, $project, [$oldQuiet], createdAt: new \DateTimeImmutable('-3 days')),
            $this->askHolding($this->em, $project, [$newBlocking], createdAt: new \DateTimeImmutable('-1 hour')),
            $this->askHolding($this->em, $project, [$newQuiet], createdAt: new \DateTimeImmutable('-2 hours')),
            $this->askHolding($this->em, $project, [$oldBlocking], createdAt: new \DateTimeImmutable('-2 days')),
        ];
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertSame(
            [(string) $asks[3]->id, (string) $asks[1]->id, (string) $asks[0]->id, (string) $asks[2]->id],
            $crawler->filter('a.lp-list-row')->each(static fn ($node): string => (string) $node->attr('data-inbox-request')),
        );
        self::assertCount(2, $crawler->filter('a.lp-list-row [data-inbox-blocking]'));
        self::assertSame('Blocking', $crawler->filter('a.lp-list-row')->first()->filter('[data-inbox-blocking]')->text());
    }

    public function test_a_row_shows_the_first_card_and_how_many_more_or_the_source(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-row-cards');
        $project = $this->inboxProject($this->em, $owner);
        $this->seedColumns($project);
        $withCards = $this->question($this->em, $project, 1, title: 'Has cards');
        foreach ([4, 5, 6] as $number) {
            $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Checkout '.$number, body: 'Body', number: $number);
            $this->em->persist($card);
            $withCards->cards->add(new InboxItemCard($withCards, $card));
        }
        $this->em->flush();
        $noCard = $this->question($this->em, $project, 2, title: 'No card');
        $withCardsAsk = $this->askHolding($this->em, $project, [$withCards], createdAt: new \DateTimeImmutable('-2 hours'));
        $noCardAsk = $this->askHolding($this->em, $project, [$noCard]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        $meta = $crawler->filter('a.lp-list-row[data-inbox-request="'.$withCardsAsk->id.'"] .lp-list-row__meta');
        self::assertSame('#4 +2', $meta->filter('[data-inbox-card]')->text());
        // A short age, and Blocking inline after a dot: "#4 Checkout 4 +2 · 2h · Blocking".
        self::assertSame('2h', $meta->filter('time')->text());
        self::assertMatchesRegularExpression('/·\s*2h\s*·\s*Blocking$/u', $meta->text());
        $source = $crawler->filter('a.lp-list-row[data-inbox-request="'.$noCardAsk->id.'"] .lp-list-row__meta');
        self::assertSame('Agent', $source->filter('[data-inbox-source]')->text());
        self::assertCount(0, $source->filter('[data-inbox-card]'));
    }

    public function test_the_byline_of_a_blocking_ask_names_the_card_that_waits_and_a_non_blocking_one_does_not(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-byline-wait');
        $project = $this->inboxProject($this->em, $owner);
        $this->seedColumns($project);
        $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Checkout', body: 'Body', number: 7);
        $this->em->persist($card);
        $question = $this->question($this->em, $project, 1, title: 'Which format?');
        $todo = $this->todo($this->em, $project, 2);
        foreach ([$question, $todo] as $item) {
            $item->cards->add(new InboxItemCard($item, $card));
        }
        $this->em->flush();
        $questionAsk = $this->askHolding($this->em, $project, [$question]);
        $todoAsk = $this->askHolding($this->em, $project, [$todo]);
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$questionAsk->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-inbox-ask__byline [data-inbox-kind-label]', 'Question');
        $link = $crawler->filter('.lp-inbox-ask__byline [data-inbox-wait-card]');
        self::assertSame('#7', $link->text());
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id, $link->attr('href'));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$todoAsk->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-inbox-ask__title', 'Review pull request 482');
        self::assertCount(0, $crawler->filter('.lp-inbox-ask__byline [data-inbox-wait-card]'));
    }

    public function test_the_byline_of_a_mixed_ask_names_the_card_of_its_blocking_item(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-byline-mixed');
        $project = $this->inboxProject($this->em, $owner);
        $this->seedColumns($project);
        $todoCard = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Checkout', body: 'Body', number: 7);
        $questionCard = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Billing', body: 'Body', number: 8);
        $this->em->persist($todoCard);
        $this->em->persist($questionCard);
        $todo = $this->todo($this->em, $project, 1);
        $question = $this->question($this->em, $project, 2);
        $todo->cards->add(new InboxItemCard($todo, $todoCard));
        $question->cards->add(new InboxItemCard($question, $questionCard));
        $bareTodo = $this->todo($this->em, $project, 3);
        $bareQuestion = $this->question($this->em, $project, 4);
        $bareTodo->cards->add(new InboxItemCard($bareTodo, $todoCard));
        $this->em->flush();
        $mixedAsk = $this->askHolding($this->em, $project, [$todo, $question]);
        $bareAsk = $this->askHolding($this->em, $project, [$bareTodo, $bareQuestion]);
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$mixedAsk->id);

        self::assertResponseIsSuccessful();
        self::assertSame(['#8'], $crawler->filter('.lp-inbox-ask__byline [data-inbox-wait-card]')->each(static fn ($link): string => $link->text()));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?ask='.$bareAsk->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-inbox-ask__byline [data-inbox-wait-card]'));
    }

    public function test_the_row_icon_is_the_first_open_item_of_an_open_ask_and_the_first_item_of_a_closed_one(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-row-icon');
        $project = $this->inboxProject($this->em, $owner);
        $answered = $this->answered($this->em, $this->question($this->em, $project, 1));
        $todo = $this->todo($this->em, $project, 2);
        $openAsk = $this->askHolding($this->em, $project, [$answered, $todo], createdAt: new \DateTimeImmutable('-2 hours'));
        $closedAnswered = $this->answered($this->em, $this->question($this->em, $project, 3, title: 'Closed question'));
        $closedTodo = $this->todo($this->em, $project, 4, 'Closed to-do');
        $closedAsk = $this->askHolding($this->em, $project, [$closedAnswered, $closedTodo], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $open = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');
        self::assertSame('todo', $open->filter('a.lp-list-row[data-inbox-request="'.$openAsk->id.'"] [data-inbox-kind]')->attr('data-inbox-kind'));

        $closed = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=closed');
        self::assertSame('question', $closed->filter('a.lp-list-row[data-inbox-request="'.$closedAsk->id.'"] [data-inbox-kind]')->attr('data-inbox-kind'));
        self::assertSame('Answered by you', $closed->filter('a.lp-list-row[data-inbox-request="'.$closedAsk->id.'"] [data-inbox-outcome]')->text());
    }

    public function test_a_closed_ask_reads_its_outcome_from_the_blocking_item_that_closed_it(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-row-outcome');
        $project = $this->inboxProject($this->em, $owner);
        $looseTodo = $this->todo($this->em, $project, 1);
        $earlier = $this->answered($this->em, $this->question($this->em, $project, 2));
        $earlier->closedAt = new \DateTimeImmutable('-3 hours');
        $closing = $this->answered($this->em, $this->question($this->em, $project, 3, title: 'Which retry?'), InboxItemState::Declined);
        $closing->closedAt = new \DateTimeImmutable('-2 hours');
        $this->em->flush();
        $ask = $this->askHolding($this->em, $project, [$looseTodo, $earlier, $closing], closedAt: new \DateTimeImmutable('-2 hours'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=closed');

        self::assertSame('Dismissed by you', $crawler->filter('a.lp-list-row[data-inbox-request="'.$ask->id.'"] [data-inbox-outcome]')->text());
    }

    public function test_the_type_filter_keeps_an_ask_when_any_item_has_the_kind(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-type-filter');
        $project = $this->inboxProject($this->em, $owner);
        $both = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1), $this->todo($this->em, $project, 2)], createdAt: new \DateTimeImmutable('-2 hours'));
        $this->askHolding($this->em, $project, [$this->question($this->em, $project, 3)]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?type=todo');

        self::assertSame(
            [(string) $both->id],
            $crawler->filter('a.lp-list-row')->each(static fn ($node): string => (string) $node->attr('data-inbox-request')),
        );
        self::assertSame('true', $crawler->filter('[data-inbox-type="todo"]')->attr('aria-current'));
        self::assertSame('Open · 2', $crawler->filter('.lp-tabs__tab')->first()->text());
        // The filter links carry the type, and "All" drops it.
        self::assertStringContainsString('type=review', (string) $crawler->filter('[data-inbox-type="review"]')->attr('href'));
        self::assertStringNotContainsString('type=', (string) $crawler->filter('[data-inbox-type="all"]')->attr('href'));

        $none = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?type=review');
        self::assertCount(0, $none->filter('a.lp-list-row'));
        self::assertSelectorExists('[data-inbox-type-empty]');
    }

    public function test_the_switch_links_keep_the_type_and_a_search_leaves_both_sides_unpressed(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-switch');
        $project = $this->inboxProject($this->em, $owner);
        $this->indexed($this->question($this->em, $project, 1, title: 'Which export format?'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?type=question');
        self::assertSame('/projects/'.$project->id.'/inbox?side=closed&type=question', $crawler->filter('[data-inbox-side="closed"]')->attr('href'));
        // With no search, nothing needs clearing.
        self::assertCount(0, $crawler->filter('.lp-filter-clear'));

        $search = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=closed&q=export');
        self::assertCount(0, $search->filter('.lp-tabs__tab[aria-current]'));
        self::assertSame('closed', $search->filter('form[data-controller="autosearch"] input[name="side"]')->attr('value'));
        // Clearing the search returns to the side the owner was on.
        self::assertSame('/projects/'.$project->id.'/inbox?side=closed', $search->filter('.lp-filter-clear')->attr('href'));
    }

    public function test_nothing_needs_you_shows_an_icon_and_no_link(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-nothing');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->answered($this->em, $this->question($this->em, $project, 1))], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertSelectorTextContains('[data-inbox-queue-empty="open-asks"]', 'Nothing needs you');
        self::assertCount(1, $crawler->filter('[data-inbox-queue-empty="open-asks"] .lp-empty-state__icon'));
        self::assertCount(0, $crawler->filter('[data-inbox-queue-empty] a'));
        self::assertSame('true', $crawler->filter('.lp-inbox-split')->attr('data-inbox-empty'));
    }

    public function test_a_row_reads_the_state_of_every_item_the_ask_holds(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-row-state');
        $project = $this->inboxProject($this->em, $owner);
        $answered = $this->answered($this->em, $this->question($this->em, $project, 1));
        $todo = $this->todo($this->em, $project, 2);
        $todo->blocking = true;
        $this->em->flush();
        $this->askHolding($this->em, $project, [$answered, $todo]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        // The ask is still waiting on its blocking to-do, so the row says so and does not read as answered.
        $row = $crawler->filter('a.lp-list-row');
        self::assertCount(1, $row);
        self::assertSame('Blocking', $row->filter('[data-inbox-blocking]')->text());
        self::assertCount(0, $row->filter('[data-inbox-outcome]'));
        self::assertSame('todo', $row->filter('[data-inbox-kind]')->attr('data-inbox-kind'));
        // Each item head of the ask pane marks its own item as blocking.
        self::assertCount(2, $crawler->filter('.lp-inbox-ask .lp-inbox-item__head'));
        self::assertCount(1, $crawler->filter('#inbox-item-2 .lp-inbox-item__head .lp-inbox-item__blocking'));
    }

    public function test_a_row_has_no_excerpt_avatar_or_status_chip(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-row-calm');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->question($this->em, $project, 1);
        $item->body = 'Compare **A & B** for `List<T>`.';
        $this->em->flush();
        $this->askHolding($this->em, $project, [$item]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        $row = $crawler->filter('a.lp-list-row');
        self::assertCount(1, $row);
        self::assertSame('Which format?', $row->filter('.lp-list-row__title')->text());
        self::assertStringNotContainsString('Compare', $row->text());
        self::assertCount(0, $row->filter('.lp-source-avatar, .lp-status-chip, .lp-list-row__body'));
    }

    public function test_a_closed_ask_shows_a_final_item_with_no_forms(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-two-asks');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->answered($this->em, $this->question($this->em, $project, 1));
        // The same item in two asks: the open one lets the owner change the answer, and the closed one holds it final.
        $this->askHolding($this->em, $project, [$item]);
        $closed = $this->askHolding($this->em, $project, [$item], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?queue=completed');

        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$closed->id.'"]'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 [data-inbox-response]'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 [data-inbox-editable]'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 form'));
    }

    public function test_open_asks_come_before_loose_items_and_closed_asks_are_on_the_closed_side(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-order');
        $project = $this->inboxProject($this->em, $owner);
        $newer = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)], createdAt: new \DateTimeImmutable('-1 hour'));
        $older = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2)], createdAt: new \DateTimeImmutable('-2 hours'));
        $leftOpen = $this->todo($this->em, $project, 3);
        $closed = $this->askHolding($this->em, $project, [$leftOpen], closedAt: new \DateTimeImmutable('-3 hours'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        // Blocking asks first, oldest first, then the item that no open ask holds.
        self::assertSame(['open-asks'], $crawler->filter('[data-inbox-section]')->each(static fn ($node): string => (string) $node->attr('data-inbox-section')));
        self::assertSame(
            [(string) $older->id, (string) $newer->id, 'item-3'],
            $crawler->filter('a.lp-list-row')->each(static fn ($node): string => (string) $node->attr('data-inbox-request')),
        );

        // The to-do still open in a closed ask gets its forms in the closed ask that holds it.
        $item = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?item=3');
        self::assertCount(1, $item->filter('[data-inbox-ask-id="'.$closed->id.'"] #inbox-item-3 form[name="inbox_response_'.$leftOpen->id.'"]'));

        $completed = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=closed');

        self::assertSame(['closed-asks'], $completed->filter('[data-inbox-section]')->each(static fn ($node): string => (string) $node->attr('data-inbox-section')));
        self::assertSame(
            [(string) $closed->id],
            $completed->filter('a.lp-list-row')->each(static fn ($node): string => (string) $node->attr('data-inbox-request')),
        );
    }

    public function test_the_page_shows_every_sent_answer_as_a_reply_with_no_card(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-editable');
        $project = $this->inboxProject($this->em, $owner);
        $inOpenAsk = $this->answered($this->em, $this->question($this->em, $project, 1));
        $inClosedAsk = $this->answered($this->em, $this->question($this->em, $project, 2));
        $this->askHolding($this->em, $project, [$inOpenAsk]);
        $this->askHolding($this->em, $project, [$inClosedAsk], closedAt: new \DateTimeImmutable());
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertCount(1, $crawler->filter('#inbox-item-1 [data-inbox-response]'));
        self::assertStringNotContainsString('This response is final', $crawler->filter('#inbox-item-1')->text());
        self::assertCount(0, $crawler->filter('#inbox-item-1 form'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 .lp-comment-composer__actions'));

        $completed = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?queue=completed');

        self::assertCount(1, $completed->filter('#inbox-item-2 [data-inbox-response]'));
        self::assertStringNotContainsString('This response is final', $completed->filter('#inbox-item-2')->text());
        self::assertCount(0, $completed->filter('#inbox-item-2 form'));
    }

    public function test_an_answer_of_zero_still_shows(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-zero');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->question($this->em, $project, 1, [], freeText: true);
        $item->state = InboxItemState::Resolved;
        $item->closedBy = InboxCloser::Owner;
        $item->note = '0';
        $this->em->flush();
        $this->askHolding($this->em, $project, [$item]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertSame('0', $crawler->filter('#inbox-item-1 .lp-inbox-thread__note blockquote')->text());
    }

    public function test_a_written_only_question_answers_inside_the_decision_block(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-written');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->question($this->em, $project, 1, [], freeText: true);
        $this->askHolding($this->em, $project, [$item]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        $block = $crawler->filter('#inbox-item-1 fieldset.lp-decision');
        self::assertCount(1, $block);
        self::assertSame('text', $block->attr('data-decision-type'));
        self::assertStringStartsWith('Your answer', $block->filter('legend')->text());
        self::assertSame('Write an answer', $block->filter('.lp-decision__kind')->text());
        self::assertCount(0, $block->filter('.lp-decision__clear, .lp-decision__option'));
        $field = $block->filter('textarea[name="inbox_response_'.$item->id.'[note]"]');
        self::assertCount(1, $field);
        self::assertSame('Your answer', $field->attr('aria-label'));
        self::assertSame('Write your answer for the agent', $field->attr('placeholder'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 textarea'));
    }

    public function test_closed_asks_page(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-paging');
        $project = $this->inboxProject($this->em, $owner);
        for ($number = 1; $number <= ShowInboxHandler::CLOSED_ASKS_PER_PAGE + 1; ++$number) {
            $item = $this->answered($this->em, $this->question($this->em, $project, $number));
            $this->askHolding($this->em, $project, [$item], closedAt: new \DateTimeImmutable('-'.$number.' minutes'));
        }
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $first = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=closed');
        self::assertCount(ShowInboxHandler::CLOSED_ASKS_PER_PAGE, $first->filter('[data-inbox-section="closed-asks"] a.lp-list-row'));
        self::assertCount(1, $first->filter('[data-inbox-item="1"]'));
        self::assertStringContainsString('side=closed', (string) $first->filter('.lp-pagination a[href*="page=2"]')->attr('href'));

        $second = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?side=closed&page=2');
        self::assertCount(1, $second->filter('[data-inbox-section="closed-asks"] a.lp-list-row'));
        self::assertCount(1, $second->filter('[data-inbox-item="'.(ShowInboxHandler::CLOSED_ASKS_PER_PAGE + 1).'"]'));

        // A page past the end shows the last one, and the old queue address still reads as the closed side.
        $past = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?queue=completed&page=9');
        self::assertCount(1, $past->filter('[data-inbox-section="closed-asks"] a.lp-list-row'));
    }

    public function test_a_search_lists_the_matching_asks_closed_ones_included_with_their_outcome(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search');
        $project = $this->inboxProject($this->em, $owner);
        $open = $this->indexed($this->question($this->em, $project, 1, title: 'Which export format?'));
        $closed = $this->indexed($this->answered($this->em, $this->question($this->em, $project, 2, title: 'Export the archive too?')));
        $closedAsk = $this->askHolding($this->em, $project, [$closed], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->indexed($this->todo($this->em, $project, 3, title: 'Review pull request 482'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=export');

        self::assertResponseIsSuccessful();
        self::assertSame('export', $crawler->filter('input[name="q"]')->attr('value'));
        $results = $crawler->filter('[data-inbox-section="search-results"]');
        self::assertCount(1, $results);
        $rows = $results->filter('a.lp-list-row');
        self::assertCount(2, $rows);
        self::assertSame('2 asks match', $crawler->filter('.lp-filter-count')->text());
        // The open item no ask holds is a row of its own, and the closed ask shows how it ended.
        self::assertCount(1, $rows->filter('[href$="item='.$open->number.'"]'));
        self::assertSame('Answered by you', $rows->filter('[data-inbox-request="'.$closedAsk->id.'"] [data-inbox-outcome]')->text());
        self::assertCount(0, $crawler->filter('[data-inbox-request="item-3"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-section="open-asks"], [data-inbox-section="closed-asks"]'));
        self::assertCount(0, $crawler->filter('.lp-tabs__tab[aria-current]'));
    }

    public function test_a_search_still_applies_the_type_filter(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search-type');
        $project = $this->inboxProject($this->em, $owner);
        $this->askHolding($this->em, $project, [$this->indexed($this->question($this->em, $project, 1, title: 'Export format?'))]);
        $todoAsk = $this->askHolding($this->em, $project, [$this->indexed($this->todo($this->em, $project, 2, title: 'Export the archive'))]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=export&type=todo');

        self::assertSame(
            [(string) $todoAsk->id],
            $crawler->filter('a.lp-list-row')->each(static fn ($node): string => (string) $node->attr('data-inbox-request')),
        );
    }

    public function test_a_search_with_no_match_says_so_and_a_blank_one_shows_the_inbox(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search-none');
        $project = $this->inboxProject($this->em, $owner);
        $this->indexed($this->question($this->em, $project, 1, title: 'Which export format?'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=invoice');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-inbox-search-empty]');
        self::assertSelectorNotExists('[data-inbox-item]');

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=%20%20');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-inbox-section="search-results"]'));
        self::assertCount(1, $crawler->filter('[data-inbox-item="1"]'));
    }

    public function test_search_results_page_and_keep_the_query_in_the_page_links(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search-paging');
        $project = $this->inboxProject($this->em, $owner);
        for ($number = 1; $number <= ShowInboxHandler::SEARCH_RESULTS_PER_PAGE + 1; ++$number) {
            $this->indexed($this->question($this->em, $project, $number, title: 'Rename column '.$number));
        }
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $first = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=column');
        self::assertCount(ShowInboxHandler::SEARCH_RESULTS_PER_PAGE, $first->filter('[data-inbox-section="search-results"] a.lp-list-row'));
        self::assertStringContainsString('q=column', (string) $first->filter('.lp-pagination a[href*="page=2"]')->attr('href'));

        $second = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=column&page=2');
        self::assertCount(1, $second->filter('[data-inbox-section="search-results"] a.lp-list-row'));
        self::assertStringContainsString('q=column', (string) $second->filter('[data-inbox-item] form')->attr('action'));

        // A page past the end shows the last page, not an empty list.
        $pastTheEnd = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=column&page=99');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $pastTheEnd->filter('[data-inbox-section="search-results"] a.lp-list-row'));
        self::assertSelectorTextSame('.lp-pagination [aria-current="page"]', '2');
        self::assertSelectorNotExists('[data-inbox-search-empty]');
    }

    public function test_a_review_ask_names_its_document_and_its_pull_request_in_the_row_and_the_panel(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-review-target');
        $project = $this->inboxProject($this->em, $owner);
        $document = new Document($owner, $project, 'Tech design: Inbox asks');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $documentItem = new InboxItem($project, 1, InboxItemKind::Review, 'Please read the design', false, InboxAskOrigin::Agent);
        $this->seedColumns($project);
        $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship the filter', body: 'Body', number: 1);
        $pullRequestLink = new CardPullRequest($card, 'https://github.com/example/app/pull/812', Forge::GitHub, 'example/app', 812);
        $pullRequestItem = new InboxItem($project, 2, InboxItemKind::Review, 'Please check the filter change', false, InboxAskOrigin::Agent);
        $pullRequest = new ForgePullRequest($project, 'github', 'example/app', 812);
        $pullRequest->title = 'Fix the board filter';
        foreach ([$document, $documentItem, $card, $pullRequestLink, $pullRequestItem, $pullRequest] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new InboxReview($documentItem, $document));
        $this->em->persist(new InboxReview($pullRequestItem, $pullRequestLink));
        $this->em->flush();
        $this->askHolding($this->em, $project, [$documentItem]);
        $this->askHolding($this->em, $project, [$pullRequestItem]);
        $this->setInboxFlag(true);
        $this->em->clear();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('a.lp-list-row .lp-list-row__subtitle')->each(static fn ($node): string => $node->text());
        sort($rows);
        self::assertSame(['Document "Tech design: Inbox asks"', 'Pull request #812: Fix the board filter'], $rows);
        self::assertContains($crawler->filter('[data-inbox-review-target]')->text(), ['Tech design: Inbox asks', '#812']);
        self::assertCount(0, $crawler->filter('.lp-inbox-ask__target'));
    }

    public function test_an_ask_names_the_target_of_a_review_item_that_follows_a_question(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-review-target-second');
        $project = $this->inboxProject($this->em, $owner);
        $document = new Document($owner, $project, 'Tech design: Inbox asks');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $question = new InboxItem($project, 1, InboxItemKind::Question, 'Which filter do you want?', false, InboxAskOrigin::Agent);
        $reviewItem = new InboxItem($project, 2, InboxItemKind::Review, 'Please read the design', false, InboxAskOrigin::Agent);
        foreach ([$document, $question, $reviewItem] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist(new InboxReview($reviewItem, $document));
        $this->em->flush();
        $this->askHolding($this->em, $project, [$question, $reviewItem]);
        $this->setInboxFlag(true);
        $this->em->clear();

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        self::assertSame('Document "Tech design: Inbox asks"', $crawler->filter('a.lp-list-row .lp-list-row__subtitle')->text());
        self::assertSame('Tech design: Inbox asks', $crawler->filter('.lp-inbox-ask__byline [data-inbox-review-target]')->text());
        self::assertSame('Tech design: Inbox asks', $crawler->filter('.lp-inbox-ask__byline [data-inbox-review-target] a')->text());
    }

    public function test_the_page_reads_every_review_in_one_query(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-review-queries');
        $project = $this->inboxProject($this->em, $owner);
        $items = [];
        for ($number = 1; $number <= 4; ++$number) {
            $document = new Document($owner, $project, 'Design '.$number);
            $document->addVersion('# Design', '<h1>Design</h1>');
            $item = new InboxItem($project, $number, InboxItemKind::Review, 'Review design '.$number, false, origin: InboxAskOrigin::Agent);
            $this->em->persist($document);
            $this->em->persist($item);
            $this->em->persist(new InboxReview($item, $document));
            $items[] = $item;
        }
        $this->em->flush();
        $this->askHolding($this->em, $project, array_slice($items, 0, 2));
        $this->setInboxFlag(true);
        $this->em->clear();

        $this->client->loginUser($owner);
        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');
        self::assertResponseIsSuccessful();
        // Guard: every item renders its review, so the count below is over real reads.
        self::assertCount(3, $crawler->filter('a.lp-list-row [data-inbox-kind="review"]'));
        self::assertGreaterThan(0, $crawler->filter('[data-inbox-review-document]')->count());

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $reviewReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (str_starts_with($sql, 'SELECT') && preg_match('/FROM inbox_reviews \w+/', $sql)) {
                    ++$reviewReads;
                }
            }
        }

        self::assertSame(1, $reviewReads);
    }

    private function indexed(InboxItem $item): InboxItem
    {
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($item);

        return $item;
    }
}
