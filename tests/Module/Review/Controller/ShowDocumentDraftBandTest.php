<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\AgentReview\DocumentReviewScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowDocumentDraftBandTest extends WebTestCase
{
    use DocumentReviewScenario;
    use WorkflowProjects;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->em();
        $this->project = $this->workflowProject('draft-band');
        $this->bindLifecycle($this->project);
    }

    public function test_the_agent_form_names_the_round_and_confirms_the_publish(): void
    {
        $crawler = $this->visit($this->designDraft());

        $band = $crawler->filter('#draft-band');
        self::assertCount(1, $band);
        self::assertStringNotContainsString('lp-draft-band--paused', (string) $band->attr('class'));
        self::assertStringContainsString('An agent reviews this draft, round 1 of 6.', $band->text());
        self::assertStringContainsString('Loupe sends it to your review when the agent review ends.', $band->text());
        self::assertCount(1, $band->filter('svg[data-draft-band-icon="agent"]'));
        self::assertCount(0, $band->filter('svg[data-draft-band-icon="paused"]'));
        self::assertSame('Draft', trim($band->filter('.lp-badge--draft')->text()));
        self::assertCount(1, $band->filter('[data-controller="modal"] dialog[data-modal-target="dialog"]'));
        self::assertCount(1, $band->filter('button[data-action="click->modal#open"]'));
        self::assertSame('Publish now', trim($band->filter('button[data-action="click->modal#open"]')->text()));

        $dialog = $band->filter('dialog');
        self::assertSame('Publish now?', trim($dialog->filter('.lp-dialog-title')->text()));
        self::assertStringContainsString('An agent still reviews this draft, round 1 of 6.', $dialog->text());
        self::assertStringContainsString('Publishing now stops the agent review and sends the draft to your review at once.', $dialog->text());
        self::assertCount(1, $dialog->filter('form[data-document-publish][action$="/publish"] input[name="_csrf_token"]'));
        self::assertCount(0, $crawler->filter('.lp-review-doc__byline [data-document-publish]'));
    }

    public function test_the_paused_form_gives_the_reason_and_links_to_the_card(): void
    {
        $card = $this->card();
        $this->pause($card, 'repair-failed');

        $crawler = $this->visit($this->designDraft($card));

        $band = $crawler->filter('#draft-band');
        self::assertStringContainsString('lp-draft-band--paused', (string) $band->attr('class'));
        self::assertStringContainsString('The agent review of this draft is paused.', $band->text());
        self::assertCount(1, $band->filter('svg[data-draft-band-icon="paused"]'));
        self::assertCount(0, $band->filter('svg[data-draft-band-icon="agent"]'));
        self::assertStringContainsString('The repair worker could not fix the failure.', $band->text());
        self::assertStringContainsString('Loupe does not publish the draft until the review goes on.', $band->text());
        self::assertSame(
            '/projects/'.$this->project->id.'/board/cards/'.$card->id,
            $band->filter('a[data-draft-band-card]')->attr('href'),
        );
        self::assertSame('Publish now', trim($band->filter('button[data-action="click->modal#open"]')->text()));
        self::assertStringContainsString('The agent review of this draft is paused, at round 1 of 6.', $band->filter('dialog')->text());
        self::assertStringNotContainsString('still reviews', $band->filter('dialog')->text());
    }

    public function test_a_draft_with_no_loop_gets_the_plain_form_that_publishes_at_once(): void
    {
        $crawler = $this->visit($this->plainDraft());

        $band = $crawler->filter('#draft-band');
        self::assertCount(1, $band);
        self::assertStringContainsString('This document is not in review yet. Publish sends it to review.', $band->text());
        self::assertCount(0, $band->filter('svg[data-draft-band-icon]'));
        self::assertCount(0, $band->filter('dialog'));
        self::assertCount(0, $band->filter('[data-controller="modal"]'));
        self::assertCount(1, $band->filter('form[data-document-publish][action$="/publish"] button[type="submit"]'));
        self::assertSame('Publish', trim($band->filter('form[data-document-publish] button')->text()));
        self::assertCount(0, $crawler->filter('.lp-review-doc__byline [data-document-publish]'));
    }

    public function test_a_document_in_review_shows_no_band_and_no_warning(): void
    {
        $crawler = $this->visit($this->plainDraft(DocumentStatus::InReview));

        self::assertCount(0, $crawler->filter('#draft-band'));
        self::assertCount(0, $crawler->filter('#draft-review-warning'));
        self::assertSame('false', $crawler->filter('form[data-controller~="review-stop-confirm"]')->attr('data-review-stop-confirm-active-value'));
    }

    public function test_finish_review_warns_on_the_agent_form_and_asks_a_second_time(): void
    {
        $crawler = $this->visit($this->designDraft());

        $form = $crawler->filter('form[data-controller~="review-stop-confirm"]');
        self::assertCount(1, $form);
        self::assertSame('true', $form->attr('data-review-stop-confirm-active-value'));
        self::assertMatchesRegularExpression('/review-stop-confirm#check[^"]*form-draft#start/', (string) $form->attr('data-action'));

        $warning = $form->filter('#draft-review-warning');
        self::assertCount(1, $warning);
        self::assertCount(1, $warning->filter('.lp-flash--warning'));
        self::assertCount(0, $warning->filter('.lp-flash__dismiss'));
        self::assertStringContainsString('An agent still reviews this draft, round 1 of 6. Your verdict stops that review now. Approve also moves the card to the next stage.', $warning->text());

        $confirm = $crawler->filter('#'.$form->attr('data-review-stop-confirm-dialog-value'));
        self::assertCount(1, $confirm);
        self::assertCount(0, $crawler->filter('.lp-finish-review #'.$form->attr('data-review-stop-confirm-dialog-value')));
        self::assertSame('Stop the agent review?', trim($confirm->filter('.lp-dialog-title')->text()));
        self::assertCount(1, $confirm->filter('dialog[data-modal-target="dialog"]'));
        self::assertSame('Go back', trim($confirm->filter('button[data-action="click->modal#close"]')->first()->text()));

        $approve = $warning->filter('[data-review-stop-confirm-copy="approved"]');
        self::assertCount(1, $approve);
        self::assertSame('Stop the review and approve', $approve->attr('data-confirm'));
        self::assertSame('You approve this draft while an agent still reviews it, round 1 of 6.', $approve->attr('data-lead'));
        self::assertStringContainsString('The card moves to the next stage.', (string) $approve->attr('data-body'));
        $changes = $warning->filter('[data-review-stop-confirm-copy="changes-requested"]');
        self::assertSame('Stop the review and request changes', $changes->attr('data-confirm'));
        self::assertStringContainsString('Your note goes to the author for a new revision.', (string) $changes->attr('data-body'));
    }

    public function test_finish_review_says_paused_and_never_that_an_agent_still_reviews(): void
    {
        $card = $this->card();
        $this->pause($card, 'repair-failed');

        $crawler = $this->visit($this->designDraft($card));

        $warning = $crawler->filter('#draft-review-warning');
        self::assertStringContainsString('The agent review of this draft is paused, at round 1 of 6. Your verdict ends that review. Approve also moves the card to the next stage.', $warning->text());
        foreach ($warning->filter('[data-review-stop-confirm-copy]') as $node) {
            $copy = new Crawler($node);
            self::assertStringContainsString('paused', (string) $copy->attr('data-lead'));
            self::assertStringNotContainsString('still reviews', (string) $copy->attr('data-lead').(string) $copy->attr('data-body'));
        }
    }

    public function test_finish_review_notes_a_plain_draft_in_one_line(): void
    {
        $crawler = $this->visit($this->plainDraft());

        $form = $crawler->filter('form[data-controller~="review-stop-confirm"]');
        self::assertSame('false', $form->attr('data-review-stop-confirm-active-value'));
        $warning = $form->filter('#draft-review-warning');
        self::assertSame('This document is still a draft.', trim($warning->text()));
        self::assertCount(0, $warning->filter('.lp-flash'));
        self::assertCount(0, $warning->filter('[data-review-stop-confirm-copy]'));
    }

    public function test_a_draft_listens_for_live_changes_and_keeps_its_stopped_lines_outside_the_replaced_ids(): void
    {
        $crawler = $this->visit($this->designDraft());

        $page = $crawler->filter('.lp-review');
        self::assertStringContainsString('review-draft-live', (string) $page->attr('data-controller'));
        self::assertMatchesRegularExpression('#^/projects/[^/]+/documents/[^/]+/review/draft-state$#', (string) $page->attr('data-review-draft-live-url-value'));
        self::assertSame('Live updates stopped. Reload the page to see the current state.', $page->attr('data-review-draft-live-stopped-value'));
        $lines = $crawler->filter('p[role="status"][data-review-draft-live-status]');
        self::assertCount(2, $lines);
        self::assertCount(0, $crawler->filter('#draft-band [data-review-draft-live-status], #draft-review-warning [data-review-draft-live-status]'));
        self::assertCount(1, $crawler->filter('.lp-finish-review [data-review-draft-live-status]'));
    }

    public function test_the_history_page_follows_the_band_and_the_warning_live(): void
    {
        $document = $this->designDraft();

        $crawler = $this->visit($document, '/review/history');

        $page = $crawler->filter('.lp-review');
        self::assertStringContainsString('review-draft-live', (string) $page->attr('data-controller'));
        self::assertStringEndsWith('/documents/'.$document->id.'/review/draft-state', (string) $page->attr('data-review-draft-live-url-value'));
        self::assertCount(1, $crawler->filter('#draft-band'));
        self::assertCount(1, $crawler->filter('#draft-review-warning'));
        self::assertSubscribesToTheDocumentTopic($crawler, $document);
    }

    public function test_an_old_version_follows_the_band_live_and_keeps_publish_out_of_it(): void
    {
        $document = $this->linkedDocument($this->card(), versions: 2);
        $tag = new Tag($this->project, 'tech-design');
        $this->em->persist($tag);
        $document->tags->add($tag);
        $this->em->flush();

        $crawler = $this->visit($document, '/review/versions/1');

        $page = $crawler->filter('.lp-review');
        self::assertStringContainsString('review-draft-live', (string) $page->attr('data-controller'));
        self::assertStringEndsWith('/documents/'.$document->id.'/review/draft-state?publish=0', (string) $page->attr('data-review-draft-live-url-value'));
        self::assertCount(0, $crawler->filter('#draft-band [data-document-publish]'));
    }

    public function test_a_diff_that_offers_finish_review_follows_its_warning_live(): void
    {
        $document = $this->linkedDocument($this->card(), versions: 2);
        $tag = new Tag($this->project, 'tech-design');
        $this->em->persist($tag);
        $document->tags->add($tag);
        $this->em->flush();

        $crawler = $this->visit($document, '/review/diff/1/2');

        $page = $crawler->filter('.lp-review');
        self::assertStringContainsString('review-draft-live', (string) $page->attr('data-controller'));
        self::assertCount(1, $crawler->filter('#draft-review-warning'));
        self::assertSubscribesToTheDocumentTopic($crawler, $document);
    }

    public function test_a_document_in_review_listens_for_the_signal_and_shows_no_band(): void
    {
        $document = $this->plainDraft(DocumentStatus::InReview);
        $crawler = $this->visit($document);

        self::assertStringContainsString('review-draft-live', (string) $crawler->filter('.lp-review')->attr('data-controller'));
        self::assertCount(0, $crawler->filter('#draft-band'));
        self::assertSubscribesToTheDocumentTopic($crawler, $document);
        self::assertCount(0, $crawler->filter('[data-review-draft-live-status]'));
    }

    private function card(): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'tech-design'), 'Ship it', '', 4);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function designDraft(?Card $card = null): Document
    {
        $document = $this->linkedDocument($card ?? $this->card());
        $tag = new Tag($this->project, 'tech-design');
        $this->em->persist($tag);
        $document->tags->add($tag);
        $this->em->flush();

        return $document;
    }

    private function plainDraft(DocumentStatus $status = DocumentStatus::Draft): Document
    {
        return $this->linkedDocument($this->card(), $status);
    }

    private function pause(Card $card, string $reason): void
    {
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);
        $handler(new PauseCardCommand($card, $reason, 'tech-design-review', PauseKind::Retries));
    }

    private function visit(Document $document, string $path = '/review'): Crawler
    {
        $owner = $this->project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $this->em->flush();
        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/documents/'.$document->id.$path);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private static function assertSubscribesToTheDocumentTopic(Crawler $crawler, Document $document): void
    {
        $topics = $crawler->filter('[data-mercure-topic]')->each(static fn (Crawler $input): string => (string) $input->attr('value'));
        self::assertNotEmpty(array_filter($topics, static fn (string $topic): bool => str_contains($topic, (string) $document->id)));
    }
}
