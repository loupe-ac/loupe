<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Inbox\Entity\InboxCloseCause;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/** The thread of each item on the inbox page: its reply, its closing message and its reply card. */
final class InboxThreadPageTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owner = $this->signedUpUser($em, 'inbox-thread-page');
        $this->project = $this->inboxProject($em, $owner);
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_an_ask_with_two_items_shows_one_thread_per_item_under_one_ask_message(): void
    {
        $question = $this->question($this->em, $this->project, 1);
        $todo = $this->todo($this->em, $this->project, 2);
        $todo->blocking = false;
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$question, $todo], context: 'Two things before I open the pull request.');

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-inbox-ask__message'));
        self::assertCount(2, $crawler->filter('.lp-inbox-ask__items [data-inbox-item]'));
        self::assertCount(2, $crawler->filter('.lp-inbox-ask__items [data-inbox-thread]'));
        self::assertCount(2, $crawler->filter('.lp-inbox-ask__items form.lp-inbox-thread__reply'));
        self::assertSame(['1', '2'], $crawler->filter('.lp-inbox-item__number')->each(static fn ($node): string => $node->text()));
        self::assertCount(1, $crawler->filter('#inbox-item-1 .lp-inbox-item__head .lp-inbox-item__blocking'));
        self::assertCount(0, $crawler->filter('#inbox-item-2 .lp-inbox-item__blocking'));
        self::assertCount(2, $crawler->filter('.lp-inbox-item__head .lp-inbox-item__kind svg'));
    }

    public function test_an_ask_with_one_item_shows_no_item_head(): void
    {
        $this->askHolding($this->em, $this->project, [$this->question($this->em, $this->project, 1)]);

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox');

        self::assertCount(0, $crawler->filter('.lp-inbox-item__head'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 form.lp-inbox-thread__reply'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 template[data-inbox-reply-draft-template] [data-controller="inbox-reply-draft"]'));
    }

    public function test_a_reply_of_the_owner_shows_the_pick_and_the_note_and_no_reply_card(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $item->note = 'Two retries max.';
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable('-30 minutes'));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?side=closed');

        $reply = $crawler->filter('#inbox-item-1 [data-inbox-response]');
        self::assertCount(1, $reply);
        self::assertSame('You', $reply->filter('.lp-comment-author')->text());
        self::assertStringContainsString('sent an answer at', $reply->filter('.lp-form-hint')->text());
        self::assertCount(0, $reply->filter('.lp-inbox-thread__chip'), 'The verb already says the owner answered.');
        self::assertSame(['JSON'], $reply->filter('[data-inbox-choices] li')->each(static fn ($node): string => trim($node->text())));
        self::assertSame('Your note for the agent', trim($reply->filter('.lp-inbox-thread__note-label')->text()));
        self::assertSame('Two retries max.', trim($reply->filter('.lp-inbox-thread__note blockquote')->text()));
        self::assertCount(0, $crawler->filter('#inbox-item-1 [data-inbox-closing]'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 form'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 template[data-inbox-reply-draft-template]'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 .lp-comment-composer__actions'));
        self::assertSame(['Closed 30m ago', 'Answered by you'], $this->closedChip($crawler));
    }

    public function test_the_chip_of_an_ask_whose_items_ended_differently_names_each_outcome(): void
    {
        $question = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $todo = $this->answered($this->em, $this->todo($this->em, $this->project, 2), InboxItemState::Declined);
        $withdrawn = $this->answered($this->em, $this->todo($this->em, $this->project, 3, 'Check the logs'), InboxItemState::Cancelled);
        $this->askHolding($this->em, $this->project, [$question, $todo, $withdrawn], closedAt: new \DateTimeImmutable('-30 minutes'));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?side=closed');

        self::assertSame(
            ['Closed 30m ago', 'Answered by you, dismissed by you, withdrawn by the agent'],
            $this->closedChip($crawler),
        );
    }

    public function test_the_chip_names_an_outcome_that_two_items_share_once(): void
    {
        $first = $this->answered($this->em, $this->todo($this->em, $this->project, 1), InboxItemState::Declined);
        $second = $this->answered($this->em, $this->todo($this->em, $this->project, 2, 'Check the logs'), InboxItemState::Declined);
        $this->askHolding($this->em, $this->project, [$first, $second], closedAt: new \DateTimeImmutable('-30 minutes'));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?side=closed');

        self::assertSame(['Closed 30m ago', 'Dismissed by you'], $this->closedChip($crawler));
    }

    /** @return array{string, string} the label of the closed chip of the byline, and the outcome its tooltip names */
    private function closedChip(Crawler $crawler): array
    {
        $chip = $crawler->filter('.lp-inbox-ask__byline [data-inbox-ask-closed]');
        $reason = trim($chip->filter('.lp-tooltip')->text());

        return [trim(str_replace($reason, '', $chip->text())), $reason];
    }

    /** @return iterable<string, array{InboxCloser, InboxCloseCause|null, string, string}> */
    public static function closers(): iterable
    {
        yield 'the agent withdrew it' => [InboxCloser::Agent, InboxCloseCause::Withdrawn, 'Agent', 'The agent withdrew this item, so it takes no answer.'];
        yield 'loupe saw the card finish' => [InboxCloser::Loupe, InboxCloseCause::CardFinished, 'Loupe', 'The card finished, so nobody needs this answer.'];
        yield 'a rule withdrew it' => [InboxCloser::Rule, InboxCloseCause::RuleWithdrew, 'Workflow rule', 'The workflow rule withdrew this item.'];
        yield 'a closer with no cause' => [InboxCloser::Loupe, null, 'Loupe', 'Loupe closed this item.'];
    }

    #[DataProvider('closers')]
    public function test_a_close_by_the_agent_loupe_or_a_rule_is_the_last_message_of_the_thread(InboxCloser $closer, ?InboxCloseCause $cause, string $author, string $sentence): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $item->state = InboxItemState::Cancelled;
        $item->closedBy = $closer;
        $item->closeCause = $cause;
        $item->closedAt = new \DateTimeImmutable('-1 hour');
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable('-1 hour'));

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?side=closed');

        $closing = $crawler->filter('#inbox-item-1 [data-inbox-closing="'.$closer->value.'"]');
        self::assertCount(1, $closing);
        self::assertSame($author, $closing->filter('.lp-comment-author')->text());
        self::assertStringContainsString('closed this', $closing->filter('.lp-form-hint')->text());
        self::assertSame('Cancelled', trim($closing->filter('.lp-status-chip')->text()), 'The message names the closer and the cause, so the chip holds the state alone.');
        self::assertSame($sentence, trim($closing->filter('.lp-comment-body')->text()));
        self::assertCount(0, $crawler->filter('#inbox-item-1 form'));
        self::assertCount(0, $crawler->filter('#inbox-item-1 .lp-comment-composer__actions'));
    }

    public function test_a_late_response_to_a_closed_item_is_refused_and_the_draft_stays_readable(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $item->state = InboxItemState::Cancelled;
        $item->closedBy = InboxCloser::Agent;
        $item->closeCause = InboxCloseCause::Withdrawn;
        $item->closedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());
        $url = '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/answer';

        $this->client->request(Request::METHOD_POST, $url, ['inbox_response_'.$item->id => ['selectedOptions' => '0', 'note' => 'Late.', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#inbox-item-1 [data-inbox-refusal]');
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-closing="agent"]', 'The agent withdrew this item');
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-draft-kind="answer"]', 'This item is closed, so it takes no answer. Your draft was not sent.');
        self::assertSelectorNotExists('#inbox-item-1 form');
        // The page shows the refused values itself, so the draft stays readable without the form memory of the browser.
        self::assertSelectorTextSame('#inbox-item-1 [data-inbox-answer-draft] .lp-inbox-item__choices', 'JSON');
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-answer-draft]', 'Late.');
        self::assertSelectorExists('#inbox-item-1 [data-inbox-answer-draft] button[data-action="form-draft-recovery#discard"]');
        self::assertSelectorExists('#inbox-item-1 [data-inbox-answer-draft] .lp-inbox-item__choices svg.lp-inbox-item__choice-icon');
        self::assertSelectorExists('#inbox-item-1 [data-inbox-refusal] + [data-controller="form-draft-recovery"]');
    }
}
