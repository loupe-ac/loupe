<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Entity\InboxDecisionMadeBy;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Repository\InboxDecisionSeenRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\AskLabelRule;
use App\Module\Workflow\Contract\AskMode;
use App\Module\Workflow\Contract\InboxAskSettings;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class InboxDecisionDigestTest extends WebTestCase
{
    use BoardColumnFixtures;
    use InboxScenario;

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
        $this->owner = $this->signedUpUser($em, 'digest');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->seedColumns($this->project);
        $this->setInboxFlag(true);
        $this->client->loginUser($this->owner);
    }

    public function test_a_card_with_new_decisions_and_no_open_item_gets_a_group_that_holds_only_the_digest(): void
    {
        $card = $this->card(1, 'Quiet card');
        $this->decision($card, '-2 hours');
        $this->decision($card, '-1 hour');

        $crawler = $this->client->request(Request::METHOD_GET, $this->inboxUrl());

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('[data-inbox-group="'.$card->id.'"]');
        self::assertCount(1, $group);
        self::assertCount(0, $group->filter('[data-inbox-request]'));
        self::assertSame('2 new decisions', trim($group->filter('[data-inbox-digest-count]')->text()));
        self::assertStringContainsString('tab=decisions', (string) $group->filter('[data-inbox-digest]')->attr('href'));
        self::assertStringNotContainsString('open', $group->filter('.lp-inbox-group__heading')->text());
        self::assertCount(0, $crawler->filter('[data-inbox-queue-empty="open-asks"]'));
        self::assertCount(1, $crawler->filter('[data-inbox-digest-pane]'));
    }

    public function test_a_card_with_an_open_item_and_new_decisions_shows_both_in_one_group(): void
    {
        $card = $this->card(1, 'Busy card');
        $item = $this->question($this->em, $this->project, 1, title: 'Which name?');
        $item->cards->add(new InboxItemCard($item, $card));
        $this->decision($card, '-1 hour');
        $this->em->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->inboxUrl());

        $group = $crawler->filter('[data-inbox-group="'.$card->id.'"]');
        self::assertCount(1, $group);
        self::assertCount(1, $group->filter('[data-inbox-request]'));
        self::assertSame('1 new decision', trim($group->filter('[data-inbox-digest-count]')->text()));
    }

    public function test_opening_the_decisions_tab_clears_the_digest_until_a_newer_decision(): void
    {
        $card = $this->card(1, 'Seen card');
        $decision = $this->decision($card, '-1 hour');

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/board/cards/'.$card->id.'?tab=decisions');
        self::assertCount(1, $crawler->filter('#card-panel-decisions[data-controller="decisions-seen"] form[data-decisions-seen-target="form"]'));
        $through = (string) $crawler->filter('input[name="through"]')->attr('value');
        self::assertSame($decision->createdAt->format('Y-m-d H:i:s.u'), $through);

        $seenUrl = '/projects/'.$this->project->id.'/board/cards/'.$card->id.'/decisions/seen';
        $this->client->request(Request::METHOD_POST, $seenUrl, ['_csrf_token' => 'csrf-token', 'through' => $through], server: ['HTTP_REFERER' => 'http://localhost'.$seenUrl]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request(Request::METHOD_GET, $this->inboxUrl());
        self::assertCount(0, $this->client->getCrawler()->filter('[data-inbox-digest]'));

        $this->decision($card, '+1 minute');
        $this->client->request(Request::METHOD_GET, $this->inboxUrl());
        self::assertSame('1 new decision', trim($this->client->getCrawler()->filter('[data-inbox-digest-count]')->text()));
    }

    public function test_a_decision_made_after_the_tab_rendered_stays_new_and_the_mark_never_moves_back(): void
    {
        $card = $this->card(1, 'Race');
        $shown = $this->decision($card, '-2 hours');
        $this->decision($card, '-1 hour');
        $seenUrl = '/projects/'.$this->project->id.'/board/cards/'.$card->id.'/decisions/seen';
        $post = fn (string $through) => $this->client->request(Request::METHOD_POST, $seenUrl, ['_csrf_token' => 'csrf-token', 'through' => $through], server: ['HTTP_REFERER' => 'http://localhost'.$seenUrl]);

        $post($shown->createdAt->format('Y-m-d H:i:s.u'));
        $this->client->request(Request::METHOD_GET, $this->inboxUrl());
        self::assertSame('1 new decision', trim($this->client->getCrawler()->filter('[data-inbox-digest-count]')->text()));

        $post(new \DateTimeImmutable('-3 hours')->format('Y-m-d H:i:s.u'));
        $this->client->request(Request::METHOD_GET, $this->inboxUrl());
        self::assertSame('1 new decision', trim($this->client->getCrawler()->filter('[data-inbox-digest-count]')->text()));
    }

    public function test_marking_seen_without_a_valid_time_is_refused(): void
    {
        $card = $this->card(1, 'Bad time');
        $seenUrl = '/projects/'.$this->project->id.'/board/cards/'.$card->id.'/decisions/seen';
        $this->client->request(Request::METHOD_POST, $seenUrl, ['_csrf_token' => 'csrf-token', 'through' => 'soon'], server: ['HTTP_REFERER' => 'http://localhost'.$seenUrl]);

        self::assertResponseStatusCodeSame(400);
    }

    public function test_a_decision_made_in_the_second_of_the_visit_stays_new(): void
    {
        $card = $this->card(1, 'Same second');
        $seenRepository = static::getContainer()->get(InboxDecisionSeenRepository::class);
        self::assertInstanceOf(InboxDecisionSeenRepository::class, $seenRepository);
        $seenRepository->markSeen($this->project, $this->owner, $card, new \DateTimeImmutable('2026-01-01 12:00:00.400000'));
        $this->decision($card, '2026-01-01 12:00:00.700000');

        $crawler = $this->client->request(Request::METHOD_GET, $this->inboxUrl());

        self::assertSame('1 new decision', trim($crawler->filter('[data-inbox-digest-count]')->text()));
    }

    public function test_a_decision_made_earlier_in_the_second_of_the_visit_counts_as_seen(): void
    {
        $card = $this->card(1, 'Same second, earlier');
        $seenRepository = static::getContainer()->get(InboxDecisionSeenRepository::class);
        self::assertInstanceOf(InboxDecisionSeenRepository::class, $seenRepository);
        $this->decision($card, '2026-01-01 12:00:00.100000');
        $seenRepository->markSeen($this->project, $this->owner, $card, new \DateTimeImmutable('2026-01-01 12:00:00.400000'));

        $crawler = $this->client->request(Request::METHOD_GET, $this->inboxUrl());

        self::assertCount(0, $crawler->filter('[data-inbox-digest]'));
    }

    public function test_with_grouping_off_each_card_gets_one_digest_row_newest_decision_first(): void
    {
        $old = $this->card(1, 'Old news');
        $new = $this->card(2, 'Fresh news');
        $this->decision($old, '-3 hours');
        $this->decision($new, '-1 hour');
        $this->decision($new, '-30 minutes');
        $this->groupingOff();

        $crawler = $this->client->request(Request::METHOD_GET, $this->inboxUrl());

        self::assertCount(0, $crawler->filter('[data-inbox-group]'));
        $rows = $crawler->filter('#inbox-rows [data-inbox-digest]');
        self::assertSame([(string) $new->id, (string) $old->id], $rows->each(static fn ($row): ?string => $row->attr('data-inbox-digest')));
        self::assertStringContainsString('2 new decisions', $rows->first()->text());
        self::assertStringContainsString('Fresh news', $rows->first()->text());
    }

    public function test_a_type_filter_shows_no_digest(): void
    {
        $this->decision($this->card(1, 'Quiet card'), '-1 hour');

        $crawler = $this->client->request(Request::METHOD_GET, $this->inboxUrl().'?type=question');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-inbox-digest]'));
    }

    private function groupingOff(): void
    {
        static::getContainer()->set(InboxAskSettings::class, new class implements InboxAskSettings {
            #[\Override]
            public function groupByCard(Uuid $projectId): bool
            {
                return false;
            }

            #[\Override]
            public function labelRule(Uuid $projectId, string $label): AskLabelRule
            {
                return new AskLabelRule(AskMode::Ask, 24, []);
            }
        });
    }

    private function card(int $number, string $title): Card
    {
        $card = new Card(project: $this->project, column: $this->column($this->project, 'backlog'), title: $title, body: 'Body', number: $number);
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }

    private function decision(Card $card, string $age): InboxDecision
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $em->find(Project::class, $this->project->id) ?? throw new \LogicException('The project exists.');
        $storedCard = $em->find(Card::class, $card->id) ?? throw new \LogicException('The card exists.');
        $decision = new InboxDecision($project, $storedCard, 'naming', 'beta', ['alpha'], InboxDecisionMadeBy::Logged, new \DateTimeImmutable($age));
        $em->persist($decision);
        $em->flush();

        return $decision;
    }

    private function inboxUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }
}
