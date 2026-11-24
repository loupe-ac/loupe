<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Form\ChangeInboxDecisionFormType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class CardDecisionsTest extends WebTestCase
{
    use BoardColumnFixtures;
    use InboxScenario;

    private const string STREAM = 'text/vnd.turbo-stream.html';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->signedUpUser($em, 'decisions');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->seedColumns($this->project);
        $this->card = new Card(project: $this->project, column: $this->column($this->project, 'backlog'), title: 'Speed up the board', body: 'Body', number: 1);
        $em->persist($this->card);
        $em->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($this->owner);
    }

    public function test_the_card_page_lists_the_decisions_newest_first_under_a_tab_with_a_count(): void
    {
        $this->decision('old', 'Cache for 30 s', ['Cache for 5 min'], InboxDecisionMadeBy::Defaulted, '-3 hours');
        $this->decision('wording', 'Wrote "No cards here yet"', [], InboxDecisionMadeBy::Logged, '-1 hour');
        $changed = $this->decision('ordering', 'Count above the title', ['Count after the title', 'Hide the count'], InboxDecisionMadeBy::Changed, '-2 hours');
        $changed->changedChoice = 'Count after the title';
        $changed->changedAt = new \DateTimeImmutable();
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Decisions 3', trim($crawler->filter('#card-tab-decisions')->text()));
        $panel = $crawler->filter('#card-panel-decisions');
        self::assertSame(['wording', 'ordering', 'old'], $panel->filter('[data-inbox-decision]')->each(static fn (Crawler $row): string => trim($row->filter('.lp-tag')->text())));
        self::assertSame('Decisions made without you', trim($panel->filter('caption')->text()));
        self::assertStringContainsString('Other option: Cache for 5 min.', $panel->filter('[data-inbox-decision-how="defaulted"]')->text());
        self::assertSame('Default at the deadline', trim($panel->filter('[data-inbox-decision-how="defaulted"] .lp-status-chip')->text()));
        self::assertSame('Logged', trim($panel->filter('[data-inbox-decision-how="logged"] .lp-status-chip')->text()));
        self::assertCount(1, $panel->filter('[data-inbox-decision-how="changed"] .lp-status-chip--ok'));
        self::assertStringContainsString('not acted on yet', $panel->filter('[data-inbox-decision-changed]')->text());
        self::assertCount(2, $panel->filter('[data-inbox-decision-change]'), 'A changed row offers no Change button.');
    }

    public function test_a_changed_decision_that_a_worker_addressed_says_so(): void
    {
        $changed = $this->decision('ordering', 'Count above the title', ['Count after the title'], InboxDecisionMadeBy::Changed, '-2 hours');
        $changed->changedChoice = 'Count after the title';
        $changed->changedAt = new \DateTimeImmutable();
        $changed->addressedAt = new \DateTimeImmutable();
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        $text = $crawler->filter('[data-inbox-decision-changed]')->text();
        self::assertStringContainsString('The worker acted on it', $text);
        self::assertStringNotContainsString('not acted on yet', $text);
    }

    public function test_the_change_query_opens_the_dialog_of_that_decision_only(): void
    {
        $first = $this->decision('one', 'alpha', ['beta']);
        $this->decision('two', 'gamma', ['delta']);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl().'?tab=decisions&change='.$first->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="modal"][data-modal-reopen-value="true"]'));
        self::assertCount(2, $crawler->filter('[data-inbox-decision-change]'));
    }

    public function test_a_card_without_decisions_shows_an_empty_tab(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertSame('Decisions', trim($crawler->filter('#card-tab-decisions')->text()));
        self::assertCount(1, $crawler->filter('[data-inbox-decisions-empty]'));
    }

    public function test_the_tab_is_gone_while_the_inbox_is_off(): void
    {
        $this->decision('naming', 'beta', ['alpha']);
        $this->setInboxFlag(false);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#card-tab-decisions'));
        self::assertCount(0, $crawler->filter('#card-panel-decisions'));
    }

    public function test_the_owner_changes_a_decision_to_another_option(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha', 'gamma']);
        $this->em->flush();

        $this->change($decision, ['option' => '1', 'note' => 'Gamma fits.']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::STREAM.'; charset=UTF-8');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="inbox-decisions"', $content);
        self::assertStringContainsString('Changed by you to gamma, not acted on yet.', $content);
        self::assertStringNotContainsString('data-inbox-decision-follow-up-offer', $content, 'A card in progress offers no follow-up.');
        $this->em->clear();
        $stored = $this->em->find(InboxDecision::class, $decision->id);
        self::assertInstanceOf(InboxDecision::class, $stored);
        self::assertSame('gamma', $stored->changedChoice);
        self::assertSame('Gamma fits.', $stored->changedNote);
        self::assertSame(InboxDecisionMadeBy::Changed, $stored->madeBy);
    }

    public function test_words_of_the_owner_win_over_a_ticked_option(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();

        $this->change($decision, ['option' => '0', 'custom' => 'delta']);

        $this->em->clear();
        self::assertSame('delta', $this->em->find(InboxDecision::class, $decision->id)?->changedChoice);
    }

    public function test_a_change_on_a_finished_card_offers_a_follow_up_card(): void
    {
        $this->card->column = $this->column($this->project, 'done');
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();

        $this->change($decision, ['option' => '0']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-inbox-decision-follow-up-offer', $content);
        self::assertStringContainsString('/projects/'.$this->project->id.'/inbox/decisions/'.$decision->id.'/follow-up', $content);
    }

    public function test_the_follow_up_button_creates_a_linked_card_and_shows_its_link(): void
    {
        $this->card->column = $this->column($this->project, 'done');
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();
        $this->change($decision, ['option' => '0']);

        $this->followUp($decision, stream: true);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-inbox-decision-follow-up-link', $content);
        $links = $this->em->getConnection()->fetchFirstColumn(
            'SELECT target_card_id FROM board_card_links WHERE source_card_id = :source',
            ['source' => $this->card->id?->toRfc4122()],
        );
        self::assertCount(1, $links);
        self::assertStringContainsString('/board/cards/'.$links[0], $content);
    }

    public function test_the_follow_up_needs_a_change_first(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();

        $this->followUp($decision);

        self::assertResponseRedirects('/projects/'.$this->project->id.'/board/cards/'.$this->card->id.'?tab=decisions');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_card_links'));
    }

    public function test_a_change_with_no_choice_redraws_the_form_with_its_error(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();

        $this->change($decision, ['note' => 'Only a note.']);

        self::assertResponseStatusCodeSame(422);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('target="decision-change-'.$decision->id.'"', $content);
        self::assertStringContainsString('Pick an option or write your own choice.', $content);
        $this->em->clear();
        self::assertNull($this->em->find(InboxDecision::class, $decision->id)?->changedAt);
    }

    public function test_a_change_without_turbo_redirects_back_to_the_tab(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();

        $this->change($decision, ['option' => '0'], stream: false);

        self::assertResponseRedirects('/projects/'.$this->project->id.'/board/cards/'.$this->card->id.'?tab=decisions');
    }

    public function test_only_the_owner_of_the_project_changes_a_decision(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();
        $this->client->loginUser($this->signedUpUser($this->em, 'stranger'));

        $this->change($decision, ['option' => '0']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->find(InboxDecision::class, $decision->id)?->changedAt);
    }

    public function test_a_decision_of_another_project_is_not_found(): void
    {
        $decision = $this->decision('naming', 'beta', ['alpha']);
        $this->em->flush();
        $other = $this->inboxProject($this->em, $this->owner);

        $this->client->request(Request::METHOD_POST, '/projects/'.$other->id.'/inbox/decisions/'.$decision->id.'/change');

        self::assertResponseStatusCodeSame(404);
    }

    /** @param list<string> $others */
    private function decision(string $label, string $choice, array $others, InboxDecisionMadeBy $madeBy = InboxDecisionMadeBy::Logged, string $age = '-1 minute'): InboxDecision
    {
        $decision = new InboxDecision($this->project, $this->card, $label, $choice, $others, $madeBy, new \DateTimeImmutable($age));
        $this->em->persist($decision);
        $this->em->flush();

        return $decision;
    }

    /** @param array<string, string> $fields */
    private function change(InboxDecision $decision, array $fields, bool $stream = true): void
    {
        $this->client->request(
            Request::METHOD_POST,
            $this->decisionUrl($decision, 'change'),
            [ChangeInboxDecisionFormType::nameFor($decision) => [...$fields, '_token' => 'csrf-token']],
            server: ['HTTP_REFERER' => 'http://localhost/', ...($stream ? ['HTTP_ACCEPT' => self::STREAM] : [])],
        );
    }

    private function followUp(InboxDecision $decision, bool $stream = false): void
    {
        $this->client->request(
            Request::METHOD_POST,
            $this->decisionUrl($decision, 'follow-up'),
            ['_csrf_token' => 'csrf-token'],
            server: ['HTTP_REFERER' => 'http://localhost/', ...($stream ? ['HTTP_ACCEPT' => self::STREAM] : [])],
        );
    }

    private function decisionUrl(InboxDecision $decision, string $action): string
    {
        return '/projects/'.$this->project->id.'/inbox/decisions/'.$decision->id.'/'.$action;
    }

    private function cardUrl(): string
    {
        return '/projects/'.$this->project->id.'/board/cards/'.$this->card->id;
    }
}
