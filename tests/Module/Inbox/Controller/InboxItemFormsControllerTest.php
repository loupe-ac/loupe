<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class InboxItemFormsControllerTest extends WebTestCase
{
    use InboxScenario;
    use BoardColumnFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->signedUpUser($em, 'inbox-forms');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->setInboxFlag(true);
        $this->client->loginUser($this->owner);
    }

    public function test_a_pull_request_review_preserves_validation_and_shows_the_completed_result(): void
    {
        $this->seedColumns($this->project);
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Review card', 'Body', number: 1);
        $target = new CardPullRequest($card, 'https://github.com/example/project/pull/12');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the pull request', true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $target);
        foreach ([$card, $target, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $ask = $this->askHolding($this->em, $this->project, [$item]);
        $name = 'inbox_response_'.$item->id;
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#inbox-item-1', 'It does not post a review to the code host.');
        // The pull request under review is a row of the linked items, not a loose link above the form.
        self::assertCount(1, $crawler->filter('[data-inbox-linked-menu] [data-linked-pull-request]'));
        self::assertCount(0, $crawler->filter('form[name="'.$name.'"] .lp-inbox-item__jump'));
        $crawler = $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'changes-requested',
            $name.'[note]' => '  ',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'Explain the changes you request in a review note.');
        self::assertSelectorExists('input[name="'.$name.'[verdict]"][value="changes-requested"]:checked');
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[note]' => 'Add a retry limit.']));
        // The ask closed with its last blocking item and nothing else is open, so no ask is named.
        self::assertResponseRedirects($this->pageUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-status]', 'Review recorded. Review the pull request is closed.');
        $this->client->request(Request::METHOD_GET, $this->completedUrl().'&ask='.$ask->id);
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Changes requested');
        self::assertSelectorTextContains('#inbox-item-1', 'Add a retry limit.');
        self::assertSelectorNotExists('form[name="'.$name.'"]');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(InboxReview::class, $review->id);
        self::assertInstanceOf(InboxReview::class, $saved);
        self::assertSame(InboxReviewVerdict::ChangesRequested, $saved->verdict);
        self::assertSame('Add a retry limit.', $saved->item->note);
        self::assertSame(InboxItemState::Resolved, $saved->item->state);
        self::assertSame(InboxCloser::Owner, $saved->item->closedBy);
    }

    public function test_a_completed_document_review_shows_its_original_answer_and_withdrawal(): void
    {
        $document = new Document($this->owner, $this->project, 'Review design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);
        $submit = static::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $verdict = $submit(new SubmitReviewCommand($this->owner, $document, 'approved', 1, 'Keep this original answer.'));
        $undo = static::getContainer()->get(UndoVerdictHandler::class);
        self::assertInstanceOf(UndoVerdictHandler::class, $undo);
        $undo(new UndoVerdictCommand($document, $this->owner, (string) $verdict->id));

        $this->client->request(Request::METHOD_GET, $this->completedUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Approved');
        self::assertSelectorTextContains('#inbox-item-1', 'Keep this original answer.');
        self::assertSelectorTextContains('[data-inbox-review-withdrawal]', 'Riley Chen withdrew this verdict');
        self::assertSelectorTextContains('[data-inbox-review-withdrawal]', 'This request keeps its original answer.');
        self::assertSelectorExists('#inbox-item-1 a[href="/projects/'.$this->project->id.'/documents/'.$document->id.'/review"]');
        // A completed review takes no further response.
        self::assertSelectorNotExists('#inbox-item-1 form');
    }

    public function test_a_document_review_completed_by_a_rule_names_the_workflow_and_the_rule(): void
    {
        $document = new Document($this->owner, $this->project, 'Review design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);
        $submit = static::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $submit(new SubmitReviewCommand($users->workflow(), $document, 'approved', 1, workflowRule: 'tech-design-auto-approve'));

        $page = $this->client->request(Request::METHOD_GET, $this->completedUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Approved');
        $result = $page->filter('[data-inbox-response]');
        self::assertStringContainsString('Workflow', $result->text());
        self::assertStringContainsString('tech-design-auto-approve', $result->filter('.lp-tag')->text());
        self::assertStringContainsString('Approved when it was published: no unanswered decision, no open thread, and the agent review passed.', $result->text());
    }

    public function test_an_inline_document_review_rejects_a_stale_version_then_records_the_current_one(): void
    {
        $document = new Document($this->owner, $this->project, 'Inline design');
        $document->addVersion('# First', '<h1>First</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $ask = $this->askHolding($this->em, $this->project, [$item]);
        $name = 'inbox_response_'.$item->id;
        $page = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertResponseIsSuccessful();
        // The document under review is a row of the linked items, with the version the form reviews.
        $row = $page->filter('[data-inbox-linked-menu] [data-inbox-review-document]');
        self::assertCount(1, $row);
        self::assertStringContainsString('Inline design', $row->text());
        self::assertStringContainsString('Version 1', $row->text());
        self::assertCount(0, $page->filter('form[name="'.$name.'"] .lp-inbox-item__jump'));
        // The form opens with a card that leads to the document, as the prototype shows.
        $card = $page->filter('form[name="'.$name.'"] [data-inbox-review-document-card]');
        self::assertCount(1, $card);
        self::assertSame('/projects/'.$this->project->id.'/documents/'.$document->id.'/review', $card->attr('href'));
        self::assertStringContainsString('Inline design', $card->text());
        self::assertStringContainsString('Read it', $card->text());
        $form = $page->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'changes-requested',
            $name.'[note]' => 'Keep this draft feedback.',
        ]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $current = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $current);
        $current->addVersion('# Second', '<h1>Second</h1>');
        $em->flush();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'The document has a newer version.');
        self::assertSelectorTextContains('textarea[name="'.$name.'[note]"]', 'Keep this draft feedback.');

        $page = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $this->client->submit($page->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'changes-requested',
            $name.'[note]' => 'Clarify the second version.',
        ]));
        self::assertResponseRedirects($this->pageUrl());
        $this->client->request(Request::METHOD_GET, $this->completedUrl().'&ask='.$ask->id);
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Changes requested');
        self::assertSelectorTextContains('#inbox-item-1', 'Version 2');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(InboxReview::class, $review->id);
        self::assertInstanceOf(InboxReview::class, $saved);
        self::assertSame('Clarify the second version.', $saved->documentReview?->note);
        self::assertSame(2, $saved->documentReview->version->versionNumber);
        self::assertSame(InboxItemState::Resolved, $saved->item->state);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-inbox-review-draft]', 'Keep this draft feedback.');
        self::assertSelectorTextContains('[data-inbox-response]', 'Clarify the second version.');
        self::assertSelectorNotExists('form[name="'.$name.'"]');
    }

    public function test_an_inline_approval_is_refused_while_a_decision_has_no_answer(): void
    {
        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand($this->project, 'Decision design', "<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->"));
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $document);
        foreach ([$item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);
        $name = 'inbox_response_'.$item->id;
        $page = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $this->client->submit($page->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'approved',
            $name.'[note]' => 'Keep this note.',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', '1 decision has no answer. Answer it, or request changes.');
        self::assertSelectorTextContains('textarea[name="'.$name.'[note]"]', 'Keep this note.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(InboxReview::class, $review->id)?->documentReview);
        self::assertSame(InboxItemState::Open, $em->find(InboxItem::class, $item->id)?->state);
    }

    public function test_inbox_ownership_does_not_bypass_document_review_permission(): void
    {
        $otherOwner = $this->signedUpUser($this->em, 'document-owner');
        $document = new Document($otherOwner, $this->project, 'Restricted design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true, origin: InboxAskOrigin::Agent);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="inbox_response_'.$item->id.'"]');
        $this->post($item, 'review-document', ['verdict' => 'approved', 'versionNumber' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_the_page_form_answers_a_question(): void
    {
        $item = $this->question($this->em, $this->project, 4, ['JSON', 'CSV'], freeText: true);
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $name = 'inbox_response_'.$item->id;

        // The form reads the pick from the hidden field, which the answer controller fills in a browser.
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([
            $name.'[selectedOptions]' => '1',
            $name.'[note]' => 'CSV, because the importer reads it.',
        ]));

        self::assertResponseRedirects($this->pageUrl());
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Resolved, $stored->state);
        self::assertSame([1], $stored->selectedOptions);
        self::assertSame('CSV, because the importer reads it.', $stored->note);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-status]', 'Answer sent: item 4.');
        self::assertSelectorNotExists('.lp-flash');
    }

    public function test_the_page_form_answers_a_workflow_question_once(): void
    {
        $item = new InboxItem(project: $this->project, number: 6, kind: InboxItemKind::Workflow, title: 'Child 3 has no design', blocking: true, origin: InboxAskOrigin::Loupe, options: ['Link', 'Design', 'Detach']);
        $this->em->persist($item);
        $ask = new InboxAsk(project: $this->project, sessionId: null, bridgeId: null);
        $ask->items->add(new InboxAskItem($ask, $item));
        $this->em->persist($ask);
        $this->em->flush();
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $name = 'inbox_response_'.$item->id;

        self::assertCount(0, $crawler->filter('#inbox-item-6 .lp-inbox-item__buttons [form]'));
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[selectedOptions]' => '2']));

        self::assertResponseRedirects($this->pageUrl());
        self::assertSame([2], $this->reload($item)->selectedOptions);
        $crawler = $this->client->request(Request::METHOD_GET, $this->completedUrl());
        self::assertCount(0, $crawler->filter('form[name="'.$name.'"]'));
        self::assertSelectorExists('#inbox-item-6 [data-inbox-editable="no"]');
    }

    public function test_a_refused_answer_re_renders_the_page_with_422_and_the_error(): void
    {
        $item = $this->question($this->em, $this->project, 1);

        $crawler = $this->post($item, 'answer', ['selectedOptions' => '0,1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'This question takes one option only.');
        $describedBy = (string) $crawler->filter('#inbox-item-1 fieldset')->attr('aria-describedby');
        self::assertSelectorTextContains('#'.$describedBy, 'This question takes one option only.');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_refused_answer_from_an_ask_that_is_not_first_shows_its_error_on_that_ask(): void
    {
        $first = $this->question($this->em, $this->project, 1);
        $second = $this->question($this->em, $this->project, 2);
        $this->askHolding($this->em, $this->project, [$first], createdAt: new \DateTimeImmutable('-2 hours'));
        $secondAsk = $this->askHolding($this->em, $this->project, [$second]);

        // The page posts the ask it shows, and the refusal must open that ask, not the first row.
        $crawler = $this->post($second, 'answer', ['selectedOptions' => '0,1'], ask: (string) $secondAsk->id, type: 'question');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-inbox-ask-id="'.$secondAsk->id.'"]'));
        self::assertSelectorTextContains('#inbox-item-2', 'This question takes one option only.');
        self::assertCount(0, $crawler->filter('#inbox-item-1'));
        self::assertSame('true', $crawler->filter('[data-inbox-type="question"]')->attr('aria-current'));
        // The forms of the pane post with the same view again.
        self::assertStringContainsString('ask='.$secondAsk->id, (string) $crawler->filter('form[name="inbox_response_'.$second->id.'"]')->attr('action'));
    }

    public function test_an_answer_past_the_length_limit_is_invalid(): void
    {
        $item = $this->question($this->em, $this->project, 1, freeText: true);

        $crawler = $this->post($item, 'answer', ['note' => str_repeat('a', RunInboxActionRequest::MAX_RESPONSE_LENGTH + 1)]);

        self::assertResponseStatusCodeSame(422);
        // The error of the note sits under the decision block, tied to the field.
        $describedBy = (string) $crawler->filter('#inbox-item-1 fieldset.lp-decision textarea')->attr('aria-describedby');
        self::assertStringContainsString('too long', $crawler->filter('#'.$describedBy)->text());
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_sent_answer_is_final_even_when_no_ask_holds_it(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $this->askHolding($this->em, $this->project, [$item]);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl().'?side=closed&item=1');
        self::assertSelectorNotExists('#inbox-item-1 form');
        self::assertSame('no', $crawler->filter('#inbox-item-1 [data-inbox-editable]')->attr('data-inbox-editable'));
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-editable]', 'This response is final');

        $this->post($item, 'answer', ['selectedOptions' => '1']);

        self::assertResponseStatusCodeSame(422);
        // The page carries a static "final" line too, so the assertion targets the refusal itself.
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-refusal]', 'no longer fits the item');
        self::assertSame([0], $this->reload($item)->selectedOptions);
    }

    public function test_an_ask_that_stays_open_stays_selected_and_the_status_counts_what_is_left(): void
    {
        $first = $this->question($this->em, $this->project, 1);
        $second = $this->question($this->em, $this->project, 2);
        $ask = $this->askHolding($this->em, $this->project, [$first, $second]);

        $this->post($second, 'answer', ['selectedOptions' => '0'], ask: (string) $ask->id);

        self::assertResponseRedirects($this->pageUrl().'?ask='.$ask->id);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-status]', 'Answer sent. 1 item of this ask is still open.');
        self::assertSelectorNotExists('.lp-flash');
        $this->client->request(Request::METHOD_GET, $this->pageUrl().'?ask='.$ask->id);
        self::assertSelectorNotExists('[data-inbox-status]');
    }

    public function test_a_closed_ask_opens_the_ask_after_it_and_the_status_links_to_the_closed_one(): void
    {
        [, $second, $third] = $this->threeAsks();

        $this->post($this->firstItem($second), 'answer', ['selectedOptions' => '0'], ask: (string) $second->id);

        self::assertResponseRedirects($this->pageUrl().'?ask='.$third->id);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-status]', 'Answer sent. Which format? is closed.');
        self::assertSame('/projects/'.$this->project->id.'/inbox?side=closed&ask='.$second->id, $crawler->filter('[data-inbox-status] a')->attr('href'));
        self::assertSelectorExists('[data-inbox-ask-id="'.$third->id.'"]');
    }

    public function test_the_last_ask_of_the_list_opens_the_one_before_it(): void
    {
        [, $second, $third] = $this->threeAsks();

        $this->post($this->firstItem($third), 'answer', ['selectedOptions' => '0'], ask: (string) $third->id);

        self::assertResponseRedirects($this->pageUrl().'?ask='.$second->id);
    }

    public function test_the_next_ask_follows_the_type_filter(): void
    {
        $first = $this->question($this->em, $this->project, 1);
        $todo = $this->todo($this->em, $this->project, 2);
        $third = $this->question($this->em, $this->project, 3);
        $firstAsk = $this->askHolding($this->em, $this->project, [$first], createdAt: new \DateTimeImmutable('-3 hours'));
        $this->askHolding($this->em, $this->project, [$todo], createdAt: new \DateTimeImmutable('-2 hours'));
        $thirdAsk = $this->askHolding($this->em, $this->project, [$third], createdAt: new \DateTimeImmutable('-1 hour'));

        $this->post($first, 'answer', ['selectedOptions' => '0'], ask: (string) $firstAsk->id, type: 'question');

        self::assertResponseRedirects($this->pageUrl().'?type=question&ask='.$thirdAsk->id);
    }

    public function test_a_completed_item_outside_every_ask_names_no_request(): void
    {
        $item = $this->todo($this->em, $this->project, 5);

        $this->post($item, 'done', []);

        // No ask holds this one, so neither side shows it once it closes. Naming
        // it would send the owner hunting for it instead of back to the
        // work that is left.
        self::assertResponseRedirects($this->pageUrl());
    }

    public function test_a_refused_form_and_a_saved_one_keep_the_closed_asks_page(): void
    {
        $last = null;
        for ($number = 1; $number <= ShowInboxHandler::CLOSED_ASKS_PER_PAGE + 1; ++$number) {
            $last = $this->answered($this->em, $this->question($this->em, $this->project, $number));
            $this->askHolding($this->em, $this->project, [$last], closedAt: new \DateTimeImmutable('-'.$number.' minutes'));
        }
        self::assertInstanceOf(InboxItem::class, $last);

        // The oldest closed ask sits on page two of the closed side.
        $crawler = $this->post($last, 'decline', ['note' => ''], page: 2, queue: 'completed');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('.lp-pagination [aria-current="page"]', '2');
        self::assertCount(1, $crawler->filter('[data-inbox-section="closed-asks"] [data-inbox-ask-id]'));
    }

    public function test_a_refused_form_and_a_saved_one_keep_the_search(): void
    {
        $question = $this->question($this->em, $this->project, 1, title: 'Which export format?');
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($question);

        $crawler = $this->post($question, 'answer', ['selectedOptions' => '0,1'], query: 'export');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-inbox-section="search-results"] [data-inbox-item="1"]'));

        $this->post($question, 'answer', ['selectedOptions' => '0'], query: 'export');

        self::assertResponseRedirects($this->pageUrl().'?side=open&q=export&item=1');
    }

    public function test_an_ask_closed_from_a_search_keeps_the_status_link_to_it(): void
    {
        $question = $this->question($this->em, $this->project, 1, title: 'Which export format?');
        $ask = $this->askHolding($this->em, $this->project, [$question]);
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($question);

        $this->post($question, 'answer', ['selectedOptions' => '0'], query: 'export');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-status]', 'Answer sent. Which export format? is closed.');
        self::assertSame('/projects/'.$this->project->id.'/inbox?side=closed&ask='.$ask->id, $crawler->filter('[data-inbox-status] a')->attr('href'));
    }

    public function test_an_item_of_two_asks_names_the_ask_the_page_shows(): void
    {
        $question = $this->question($this->em, $this->project, 1, title: 'Which export format?');
        $this->askHolding($this->em, $this->project, [$question], createdAt: new \DateTimeImmutable('-2 hours'));
        $this->askHolding($this->em, $this->project, [$question, $this->question($this->em, $this->project, 2)], createdAt: new \DateTimeImmutable('-1 hour'));
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($question);

        $this->post($question, 'answer', ['selectedOptions' => '0'], query: 'export');

        $this->client->followRedirect();
        // The page shows the newer ask, which is still open, so the line counts its items left.
        self::assertSelectorTextContains('[data-inbox-status]', '1 item of this ask is still open.');
        self::assertSelectorNotExists('[data-inbox-status] a');
    }

    public function test_marking_a_to_do_done(): void
    {
        $item = $this->todo($this->em, $this->project, 2);

        $this->post($item, 'done', []);

        self::assertResponseRedirects($this->pageUrl());
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Resolved, $stored->state);
        self::assertNotNull($stored->closedAt);
    }

    public function test_marking_a_to_do_done_keeps_the_note_and_shows_it(): void
    {
        $item = $this->todo($this->em, $this->project, 2);
        $ask = $this->askHolding($this->em, $this->project, [$item]);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertCount(1, $crawler->filter('#inbox-item-2 form.lp-inbox-done textarea[name="inbox_response_'.$item->id.'[note]"]'));

        $this->post($item, 'done', ['note' => '  Exported to S3.  ']);

        self::assertResponseRedirects($this->pageUrl().'?ask='.$ask->id);
        $stored = $this->reload($item);
        self::assertSame([InboxItemState::Resolved, 'Exported to S3.'], [$stored->state, $stored->note]);
        $crawler = $this->client->request(Request::METHOD_GET, $this->completedUrl().'&ask='.$ask->id.'&item=2');
        self::assertStringContainsString('Exported to S3.', $crawler->filter('#inbox-item-2')->text());
    }

    public function test_a_to_do_shows_its_suggested_actions_in_the_split_button_beside_dismiss_and_done(): void
    {
        $this->seedColumns($this->project);
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Export card', 'Body', number: 1);
        $this->em->persist($card);
        $item = $this->todo($this->em, $this->project, 2);
        $item->suggestedActions = [
            ['action' => 'open', 'reason' => 'Read the card first.', 'recommended' => true, 'cardId' => null],
            ['action' => 'send-agent', 'reason' => 'Let an agent do it.', 'recommended' => false, 'cardId' => null],
        ];
        $item->cards->add(new InboxItemCard($item, $card));
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSame('Read the card first.', trim($crawler->filter('#inbox-item-2 [data-inbox-reply-heading]')->text()));
        self::assertCount(1, $crawler->filter('#inbox-item-2 .inbox-split__main[data-inbox-action="open"]'));
        self::assertCount(0, $crawler->filter('#inbox-item-2 [data-inbox-action="send-agent"]'));
        self::assertCount(1, $crawler->filter('#inbox-item-2 .lp-inbox-done'));
        $row = $crawler->filter('#inbox-item-2 .lp-comment-composer__actions');
        self::assertCount(1, $row, 'Dismiss, Mark done and the split button share one reply card row.');
        self::assertCount(1, $row->filter('.inbox-split'));
        $doneForm = $crawler->filter('#inbox-item-2 form.lp-inbox-done');
        self::assertCount(1, $row->filter('button[form="'.$doneForm->attr('id').'"][formaction*="/actions/dismiss"]'));
        self::assertCount(1, $row->filter('[data-inbox-done]'));
        self::assertSame($doneForm->attr('id'), $row->filter('[data-inbox-done]')->attr('form'));
        self::assertStringContainsString('/actions/complete', (string) $doneForm->attr('action'));
        self::assertCount(0, $doneForm->filter('button'), 'The done form keeps no second Mark done button.');
        $note = $row->ancestors()->filter('form')->first()->filter('textarea[form="'.$doneForm->attr('id').'"]');
        self::assertCount(1, $note, 'The reply card holds the note of the done form.');
        self::assertSame('inbox_response_'.$item->id.'[note]', $note->attr('name'));
    }

    public function test_a_to_do_with_no_suggestion_shows_no_split_button(): void
    {
        $item = $this->todo($this->em, $this->project, 2);
        $this->askHolding($this->em, $this->project, [$item]);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#inbox-item-2 .inbox-split'));
        self::assertCount(1, $crawler->filter('#inbox-item-2 .lp-inbox-done'));
    }

    public function test_a_done_on_a_final_to_do_is_refused_in_place(): void
    {
        $item = $this->answered($this->em, $this->todo($this->em, $this->project, 2), InboxItemState::Declined);
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());

        $this->post($item, 'done', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Declined, $this->reload($item)->state);
    }

    public function test_declining_with_a_note(): void
    {
        $item = $this->question($this->em, $this->project, 3);

        $this->post($item, 'decline', ['note' => 'Not sure what you mean.']);

        self::assertResponseRedirects($this->pageUrl());
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Declined, $stored->state);
        self::assertSame('Not sure what you mean.', $stored->note);
    }

    public function test_a_decline_of_an_item_the_agent_withdrew_is_refused(): void
    {
        $item = $this->answered($this->em, $this->todo($this->em, $this->project, 3), InboxItemState::Cancelled);
        $this->askHolding($this->em, $this->project, [$item]);

        $this->post($item, 'decline', ['note' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-3 [data-inbox-refusal]', 'no longer fits the item');
        self::assertSame(InboxItemState::Cancelled, $this->reload($item)->state);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function actions(): iterable
    {
        yield 'answer' => ['answer', ['selectedOptions' => '0']];
        yield 'done' => ['done', []];
        yield 'decline' => ['decline', ['note' => '']];
        yield 'review-pull-request' => ['review-pull-request', ['verdict' => 'approved', 'expectedUrl' => 'https://github.com/example/project/pull/12']];
        yield 'review-document' => ['review-document', ['verdict' => 'approved', 'versionNumber' => '1']];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('actions')]
    public function test_a_forged_token_changes_nothing(string $action, array $fields): void
    {
        $item = 'done' === $action ? $this->todo($this->em, $this->project, 1) : $this->question($this->em, $this->project, 1);
        $url = $this->actionUrl($item, $action);

        $this->client->request(Request::METHOD_POST, $url, ['inbox_response_'.$item->id => [...$fields, '_token' => 'forged']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('actions')]
    public function test_a_stranger_cannot_respond(string $action, array $fields): void
    {
        $item = 'done' === $action ? $this->todo($this->em, $this->project, 1) : $this->question($this->em, $this->project, 1);
        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-stranger'));

        $this->post($item, $action, $fields);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('actions')]
    public function test_a_response_answers_404_while_the_inbox_is_off(string $action, array $fields): void
    {
        $item = 'done' === $action ? $this->todo($this->em, $this->project, 1) : $this->question($this->em, $this->project, 1);
        $this->setInboxFlag(false);

        $this->post($item, $action, $fields);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_item_of_another_project_is_not_found_under_this_one(): void
    {
        $other = $this->inboxProject($this->em, $this->owner);
        $item = $this->todo($this->em, $other, 1);

        $this->client->request(Request::METHOD_POST, '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/complete', ['inbox_response_'.$item->id => ['_token' => 'csrf-token']]);

        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, string> $fields */
    private function post(InboxItem $item, string $action, array $fields, ?int $page = null, ?string $query = null, ?string $queue = null, ?string $ask = null, ?string $side = null, ?string $type = null): Crawler
    {
        $prefix = 'inbox_response_';
        $parameters = array_filter(['queue' => $queue, 'side' => $side, 'type' => $type, 'ask' => $ask, 'page' => $page, 'q' => $query], static fn (int|string|null $value): bool => null !== $value);
        $url = $this->actionUrl($item, $action).([] === $parameters ? '' : '?'.http_build_query($parameters));

        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a same-origin Referer lets stand in for a signed token.
        return $this->client->request(Request::METHOD_POST, $url, [$prefix.$item->id => [...$fields, '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    /** @return array{InboxAsk, InboxAsk, InboxAsk} three open asks of one question each, oldest first */
    private function threeAsks(): array
    {
        $asks = [];
        foreach ([1, 2, 3] as $number) {
            $item = $this->question($this->em, $this->project, $number);
            $asks[] = $this->askHolding($this->em, $this->project, [$item], createdAt: new \DateTimeImmutable('-'.(4 - $number).' hours'));
        }

        return $asks;
    }

    private function firstItem(InboxAsk $ask): InboxItem
    {
        $link = $ask->items->first();

        return false === $link ? throw new \LogicException('The ask holds no item.') : $link->item;
    }

    /** $action is the name the tests of this class use for a response. The address names the kind. */
    private function actionUrl(InboxItem $item, string $action): string
    {
        $kind = ['answer' => 'answer', 'done' => 'complete', 'decline' => 'dismiss', 'review-pull-request' => 'submit-review', 'review-document' => 'submit-review'][$action];

        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/'.$kind;
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }

    private function completedUrl(): string
    {
        return $this->pageUrl().'?side=closed';
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }
}
