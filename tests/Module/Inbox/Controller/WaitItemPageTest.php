<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Contract\MarkerSubject;
use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A wait item on the inbox page: Loupe as its sender, its waits, and Dismiss as its one response. */
final class WaitItemPageTest extends WebTestCase
{
    use InboxScenario;
    use InboxFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Document $document;
    private ?WorkerRun $run = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owner = $this->signedUpUser($em, 'inbox-wait-page');
        $this->project = $this->inboxProject($em, $owner);
        $this->seedColumns($this->project);
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Ship it', 'Body', number: 4);
        $this->document = new Document($owner, $this->project, 'Tech design');
        $this->document->addVersion('# One', '<h1>One</h1>');
        $this->card->documents->add(new CardDocument($this->card, $this->document));
        $this->card->column = $this->stageColumn($em, $this->project, 'tech-design');
        $em->persist($this->card);
        $em->persist($this->document);
        $em->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_the_row_and_the_panel_name_loupe_as_the_sender_with_no_presence(): void
    {
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        // The wait links its card, so the row names the card and the pane names the sender.
        self::assertSelectorTextContains('.lp-list-row__meta [data-inbox-card]', '#4');
        self::assertSelectorNotExists('[data-inbox-source="agent"]');
        self::assertSelectorExists('.lp-list-row [data-inbox-kind="review"]');
        self::assertSelectorTextSame('.lp-inbox-ask__source', 'From Loupe');
        self::assertCount(1, $crawler->filter('.lp-inbox-ask__head [data-inbox-source="loupe"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-presence]'));
        self::assertCount(0, $crawler->filter('.lp-avatar__dot'));
    }

    public function test_the_byline_names_the_waiting_card_and_opens_it_in_the_drawer(): void
    {
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-inbox-ask__byline [data-inbox-kind-label]', '#4 Card #4 waits on you Ship it');
        $link = $crawler->filter('.lp-inbox-ask__byline [data-inbox-wait-card]');
        self::assertSame('#4', $link->text());
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $link->attr('href'));
        self::assertSame('card-drawer-frame', $link->attr('data-turbo-frame'));
    }

    public function test_the_panel_names_the_card_once_and_shows_no_ask_context(): void
    {
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-inbox-ask__title', 'Review "Tech design", version 1');
        self::assertCount(0, $crawler->filter('.lp-inbox-ask__context'));
        self::assertCount(1, $crawler->filter('[data-inbox-linked-menu] [data-linked-card="'.$this->card->id.'"]'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function loupeCloses(): iterable
    {
        yield 'done, after a verdict' => ['approve', 'Resolved by Loupe'];
        yield 'obsolete, after the card finished' => ['finish', 'Cancelled by Loupe: the card finished'];
    }

    #[DataProvider('loupeCloses')]
    public function test_an_item_loupe_closed_says_so_and_refuses_a_dismissal(string $cause, string $label): void
    {
        $this->reconcile();
        if ('approve' === $cause) {
            $this->document->status = DocumentStatus::Approved;
        } else {
            $this->card->column = $this->column($this->project, 'done');
        }
        $this->em->flush();
        $this->reconcile();
        $item = $this->onlyWatch()->item;
        self::assertSame('approve' === $cause ? InboxItemState::Resolved : InboxItemState::Cancelled, $item->state);

        $this->client->request(Request::METHOD_GET, $this->pageUrl().'?queue=completed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#inbox-item-1 [data-inbox-editable]', $label);
        self::assertSelectorTextNotContains('#inbox-item-1', 'The agent closed');

        $url = '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/decline';
        $this->client->request(Request::METHOD_POST, $url, ['inbox_decline_'.$item->id => ['closeNote' => '', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-refusal]', 'Loupe closed this item');
        self::assertSame($item->state, $this->onlyWatch()->item->state);
    }

    public function test_a_search_result_outside_its_ask_still_names_loupe(): void
    {
        $this->reconcile();
        $item = $this->onlyWatch()->item;
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl().'?q='.rawurlencode('Tech design'));

        self::assertResponseIsSuccessful();
        $results = $crawler->filter('[data-inbox-section="search-results"]');
        self::assertCount(1, $results->filter('[data-inbox-item]'));
        self::assertCount(1, $results->filter('[data-inbox-item="'.$item->number.'"]'));
        self::assertCount(1, $crawler->filter('.lp-inbox-ask__head [data-inbox-source="loupe"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-source="agent"]'));
    }

    public function test_the_thread_lists_the_current_and_ended_waits_and_makes_review_the_main_action(): void
    {
        $this->reconcile();
        $this->document->addVersion('# Two', '<h1>Two</h1>');
        $this->em->flush();
        $this->reconcile();
        $item = $this->onlyWatch()->item;

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $current = $crawler->filter('#inbox-item-1 [data-inbox-wait="current"]');
        self::assertCount(1, $current);
        self::assertStringContainsString('The document "Tech design" waits for your review.', $current->text());
        $main = $crawler->filter('#inbox-item-1 .inbox-split__main');
        self::assertCount(1, $main);
        self::assertSame('Review', trim($main->text()));
        self::assertSame('/projects/'.$this->project->id.'/documents/'.$this->document->id.'/review', $main->attr('href'));
        $ended = $crawler->filter('#inbox-item-1 [data-inbox-wait="ended"]');
        self::assertCount(1, $ended);
        self::assertStringContainsString('waits for your review', $ended->text());
        self::assertStringContainsString('Closed', $ended->text());

        self::assertCount(0, $crawler->filter('form[name="inbox_done_'.$item->id.'"]'));
        self::assertCount(0, $crawler->filter('form[name="inbox_answer_'.$item->id.'"]'));
        $dismiss = $crawler->filter('form[name="inbox_decline_'.$item->id.'"]');
        self::assertCount(1, $dismiss);
        $row = $crawler->filter('#inbox-item-1 button[data-inbox-action="dismiss"]');
        self::assertSame('inbox_decline_'.$item->id, $row->attr('form'));
        self::assertStringContainsString('Dismiss', $row->text());
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $crawler->filter('#inbox-item-1 a[data-inbox-action="open"]')->attr('href'));
    }

    public function test_a_wait_of_a_deleted_document_shows_its_reason_with_no_link(): void
    {
        $this->reconcile();
        $this->em->remove($this->document);
        $this->em->flush();
        $this->em->clear();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $wait = $crawler->filter('#inbox-item-1 [data-inbox-wait]');
        self::assertCount(1, $wait);
        self::assertStringContainsString('A deleted document, version 1, of card 4 waited for your review.', $wait->text());
        self::assertStringNotContainsString('""', $wait->text());
        self::assertCount(0, $wait->filter('a'));
    }

    public function test_a_run_wait_opens_its_card_in_the_drawer(): void
    {
        $this->document->status = DocumentStatus::Approved;
        $this->blockedRun();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $wait = $crawler->filter('#inbox-item-1 [data-inbox-wait="current"]');
        self::assertSame('Implement worker', $wait->filter('.lp-comment-author')->text());
        self::assertSame('Needs the API key. Add it to the bridge config.', trim($wait->filter('.lp-review-doc__prose')->text()));
        $open = $crawler->filter('#inbox-item-1 a[data-inbox-action="open"]');
        self::assertCount(1, $open);
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $open->attr('href'));
        self::assertSame('card-drawer-frame', $open->attr('data-turbo-frame'));
        self::assertSame('click->card-drawer#prepare', $open->attr('data-action'));
    }

    public function test_a_run_wait_on_its_own_card_page_offers_no_link_to_that_card(): void
    {
        $this->document->status = DocumentStatus::Approved;
        $this->blockedRun();

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/board/cards/'.$this->card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#inbox-item-1 [data-inbox-wait="current"]'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 [data-inbox-action="open"]'));
        // The run cannot resume and Open is the card itself, so the first enabled action, Send an agent, takes the main part.
        self::assertSame('send-agent', $crawler->filter('#inbox-item-1 .inbox-split__main')->attr('data-inbox-action'));
        self::assertSame('true', $crawler->filter('#inbox-item-1 .inbox-split .lp-menu__panel [data-inbox-action="start-session"]')->attr('aria-disabled'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 .inbox-split .lp-menu__panel button[data-inbox-action="dismiss"]'));
    }

    public function test_send_an_agent_from_the_card_page_queues_the_agent(): void
    {
        $this->document->status = DocumentStatus::Approved;
        $this->blockedRun();
        $cardUrl = '/projects/'.$this->project->id.'/board/cards/'.$this->card->id;
        $crawler = $this->client->request(Request::METHOD_GET, $cardUrl);

        $this->client->submit($crawler->filter('#inbox-item-1 .inbox-split__main')->form());

        self::assertResponseRedirects($cardUrl.'?tab=conversation');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The agent of item 1 is queued.', $crawler->text());
        $actions = static::getContainer()->get(InboxActionRepository::class);
        self::assertInstanceOf(InboxActionRepository::class, $actions);
        self::assertSame([InboxActionKind::SendAgent], array_map(static fn (InboxAction $action): InboxActionKind => $action->kind, $actions->findAll()));
        // The agent answers the open wait, so the queued You card replaces the reply.
        self::assertNotNull($actions->findAll()[0]->waitId);
        self::assertCount(1, $crawler->filter('#inbox-item-1 [data-inbox-action-status="queued"]'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 .inbox-split'));
    }

    public function test_dismiss_closes_the_item_declined_and_leaves_nothing_that_needs_you(): void
    {
        $this->reconcile();
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $this->client->submit($crawler->selectButton('Dismiss')->form());

        // The ask closed with its item and no other ask is open, so the page names none.
        self::assertResponseRedirects($this->pageUrl());
        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Declined, $watch->item->state);
        self::assertNotNull($watch->dismissedAt);
        self::assertNotNull($watch->closedAt);
        $wait = $watch->waits->first();
        self::assertNotFalse($wait);
        self::assertSame(InboxCardWaitEndReason::Dismissed, $wait->endReason);

        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('[data-inbox-queue-empty="open-asks"]');
        self::assertSelectorTextContains('[data-inbox-status]', 'Closed.');
        self::assertCount(0, $crawler->filter('#inbox-item-1 form'));
    }

    public function test_the_reply_heading_shows_the_reason_of_the_suggested_default(): void
    {
        $this->document->status = DocumentStatus::Approved;
        $this->blockedRun(['suggestedActions' => [
            ['action' => 'retry-card', 'reason' => 'The card is not paused.', 'recommended' => true],
            ['action' => 'open', 'reason' => 'Add the key, then read the card.'],
        ]]);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSame('Add the key, then read the card.', trim($crawler->filter('#inbox-item-1 [data-inbox-reply-heading]')->text()));
    }

    public function test_the_reply_heading_falls_back_to_the_label_with_no_suggestion(): void
    {
        $this->document->status = DocumentStatus::Approved;
        $this->blockedRun();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(trim($crawler->filter('#inbox-item-1 .inbox-split__main')->text()), trim($crawler->filter('#inbox-item-1 [data-inbox-reply-heading]')->text()));
    }

    /** @param array<string, mixed>|null $resultFields */
    private function blockedRun(?array $resultFields = null): void
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: 4,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            output: 'Needs the API key. Add it to the bridge config.',
        );
        $run->resultFields = $resultFields;
        $this->em->persist($run);
        $this->em->flush();
        $this->run = $run;
        $this->reconcile();
    }

    /** Stores the inbox set the engine would store for the document and the blocked run, then reconciles. */
    private function reconcile(): void
    {
        $entries = [];
        if (DocumentStatus::InReview === $this->document->status) {
            $entries[] = $this->inboxEntry('document-in-review', new MarkerSubject(MarkerSubjectType::Document, ['document' => (string) $this->document->id, 'version' => (string) $this->document->currentVersion()->versionNumber]));
        }
        if (null !== $this->run) {
            $entries[] = $this->inboxEntry('run-needs-you', new MarkerSubject(MarkerSubjectType::WorkerRun, ['run' => (string) $this->run->id]));
        }
        $this->storeInbox($this->em, $this->card, ...$entries);
        $reconciler = static::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $reconciler->reconcile($this->project, [(string) $this->card->id]);
    }

    private function onlyWatch(): InboxCardWatch
    {
        $this->em->clear();
        $watches = static::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $found = $watches->findBy(['cardId' => $this->card->id]);
        self::assertCount(1, $found);

        return $found[0];
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }
}
