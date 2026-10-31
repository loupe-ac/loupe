<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Messenger;

use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Messenger\NotifyInboxOwner;
use App\Module\Inbox\Messenger\NotifyInboxOwnerHandler;
use App\Module\Inbox\Messenger\NotifyInboxOwnerKind;
use App\Module\Inbox\Repository\InboxAskRepository;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\InboxOpenPushTags;
use App\Module\Notification\Entity\NotificationPreference;
use App\Module\Notification\Messenger\DeliverWebPush;
use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationEvent;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\NotificationPreferences;
use App\Module\Notification\Service\Notifier;
use App\Module\Notification\Service\PresenceStore;
use App\Module\Project\Entity\Project;
use App\Module\Review\Service\ReferenceTokens;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\RecordingMessageBus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotifyInboxOwnerHandlerTest extends KernelTestCase
{
    use InboxFixtures;
    use NotificationScenario;

    private EntityManagerInterface $em;
    private Project $project;

    private RecordingMessageBus $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->bus = new RecordingMessageBus();
        $owner = $this->owner($em, 'push-owner');
        $this->project = $this->project($em, $owner, 'push-project');
        $em->flush();
        $this->makeDevice(self::getContainer(), $owner);
    }

    public function test_an_open_ask_pushes_the_project_name_the_first_title_and_the_tap_url(): void
    {
        $ask = $this->askWith('Can the design skip review?');

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        $payload = $this->onlyPayload();
        $notification = $payload['notification'];
        self::assertSame($this->project->name, $notification['title']);
        self::assertSame('Can the design skip review?', $notification['body']);
        self::assertSame('ask-'.$ask->id, $notification['tag']);
        self::assertSame($notification['navigate'], $notification['data']['url']);
        self::assertSame(
            \sprintf('%s/projects/%s/inbox?ask=%s', rtrim((string) self::getContainer()->getParameter('app.url'), '/'), $this->project->id, $ask->id),
            $notification['navigate'],
        );
        self::assertSame('high', $this->sent()[0]->urgency);
    }

    public function test_a_reference_token_in_an_ask_title_is_pushed_as_plain_text(): void
    {
        $ask = $this->askWith('Does card:1116 block pr:acme/loupe#1295?');

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertSame('Does card #1116 block acme/loupe#1295?', $this->onlyPayload()['notification']['body']);
    }

    public function test_an_ask_with_more_items_says_how_many_more(): void
    {
        $ask = $this->askWith('First question', 'Second question', 'Third question');

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertSame('First question +2 more questions', $this->onlyPayload()['notification']['body']);
    }

    public function test_an_ask_with_one_more_item_uses_the_singular(): void
    {
        $ask = $this->askWith('First question', 'Second question');

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertSame('First question +1 more question', $this->onlyPayload()['notification']['body']);
    }

    public function test_a_mixed_ask_shows_the_first_question_and_counts_only_questions(): void
    {
        $ask = $this->askWithItems([InboxItemKind::Todo, 'Rotate the key'], [InboxItemKind::Question, 'Which column?'], [InboxItemKind::Todo, 'Check the logs'], [InboxItemKind::Question, 'Which owner?']);

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertSame('Which column? +1 more question', $this->onlyPayload()['notification']['body']);
    }

    public function test_an_ask_with_no_question_shows_its_first_item_and_no_count(): void
    {
        $ask = $this->askWithItems([InboxItemKind::Wait, 'Card 12 waits for you'], [InboxItemKind::Todo, 'Check the logs']);

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertSame('Card 12 waits for you', $this->onlyPayload()['notification']['body']);
    }

    public function test_a_closed_ask_sends_nothing(): void
    {
        $ask = $this->askWith('Which column?');
        $ask->closedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertSame([], $this->sent());
    }

    public function test_an_open_ask_does_send(): void
    {
        $ask = $this->askWith('Which column?');

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        self::assertCount(1, $this->sent(), 'guards the closed case above, which passes when the handler never runs');
    }

    public function test_a_missing_ask_sends_nothing(): void
    {
        $this->handle(new NotifyInboxOwner((string) Uuid::v7(), NotifyInboxOwnerKind::Ask));

        self::assertSame([], $this->sent());
    }

    public function test_an_open_notice_pushes_with_its_number_and_the_other_event(): void
    {
        $this->em->persist(new NotificationPreference($this->project->owner, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $notice = $this->notice(507);

        $this->handle(new NotifyInboxOwner((string) $notice->id, NotifyInboxOwnerKind::Notice));

        $notification = $this->onlyPayload()['notification'];
        self::assertSame('Work waits for a bridge', $notification['body']);
        self::assertSame('item-'.$notice->id, $notification['tag']);
        self::assertSame('item=507', parse_url($notification['navigate'], \PHP_URL_QUERY));
        self::assertSame('normal', $this->sent()[0]->urgency);
    }

    public function test_a_notice_is_not_pushed_while_the_other_event_is_off(): void
    {
        $notice = $this->notice(507);

        $this->handle(new NotifyInboxOwner((string) $notice->id, NotifyInboxOwnerKind::Notice));

        self::assertSame([], $this->sent());
    }

    public function test_a_notice_that_is_no_longer_open_sends_nothing(): void
    {
        $this->em->persist(new NotificationPreference($this->project->owner, NotificationEvent::OtherInboxItem, NotificationChannel::WebPush, true));
        $notice = $this->notice(507);
        $notice->state = InboxItemState::Resolved;
        $this->em->flush();

        $this->handle(new NotifyInboxOwner((string) $notice->id, NotifyInboxOwnerKind::Notice));

        self::assertSame([], $this->sent());
    }

    public function test_the_open_tags_list_every_open_ask_and_notice_of_the_owner_and_nothing_else(): void
    {
        $ask = $this->askWith('Which column?');
        $older = $this->askWith('Another one?');
        $closed = $this->askWith('Done already?');
        $closed->closedAt = new \DateTimeImmutable();
        $notice = $this->notice(509);
        $strangerProject = $this->project($this->em, $this->owner($this->em, 'push-stranger'), 'push-stranger');
        $stranger = new InboxAsk(project: $strangerProject, sessionId: Uuid::v4());
        $this->em->persist($stranger);
        $this->em->flush();

        $this->handle(new NotifyInboxOwner((string) $ask->id, NotifyInboxOwnerKind::Ask));

        $tags = $this->onlyPayload()['notification']['data']['openTags'];
        self::assertSame('ask-'.$ask->id, $tags[0], 'the own tag leads');
        self::assertEqualsCanonicalizing(['ask-'.$ask->id, 'ask-'.$older->id, 'item-'.$notice->id], $tags);
    }

    private function askWith(string ...$titles): InboxAsk
    {
        return $this->askWithItems(...array_map(static fn (string $title): array => [InboxItemKind::Question, $title], $titles));
    }

    /** @param array{InboxItemKind, string} ...$items */
    private function askWithItems(array ...$items): InboxAsk
    {
        static $number = 0;
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4());
        $this->em->persist($ask);
        foreach ($items as $index => [$kind, $title]) {
            $item = new InboxItem(project: $this->project, number: ++$number, kind: $kind, title: $title, blocking: InboxItemKind::Todo !== $kind, origin: InboxAskOrigin::Agent, freeText: true);
            $this->em->persist($item);
            $ask->items->add(new InboxAskItem($ask, $item, new \DateTimeImmutable(\sprintf('2026-10-25 12:00:0%d', $index))));
        }
        $this->em->flush();

        return $ask;
    }

    private function notice(int $number): InboxItem
    {
        $notice = new InboxItem(project: $this->project, number: $number, kind: InboxItemKind::Notice, title: 'Work waits for a bridge', blocking: false, origin: InboxAskOrigin::Loupe);
        $this->em->persist($notice);
        $this->em->flush();

        return $notice;
    }

    /** @return list<DeliverWebPush> */
    private function sent(): array
    {
        return $this->bus->dispatched(DeliverWebPush::class);
    }

    /** @return array<string, mixed> */
    private function onlyPayload(): array
    {
        self::assertCount(1, $this->sent());

        return json_decode($this->sent()[0]->payload, true, flags: \JSON_THROW_ON_ERROR);
    }

    private function handle(NotifyInboxOwner $message): void
    {
        $container = self::getContainer();
        $this->em->clear();
        $asks = $container->get(InboxAskRepository::class);
        $items = $container->get(InboxItemRepository::class);
        $notifier = new Notifier(
            self::availability(),
            $container->get(NotificationPreferences::class),
            new PresenceStore(new \Symfony\Component\Cache\Adapter\ArrayAdapter(), $container->get(ClockInterface::class)),
            $container->get(PushDeviceRepository::class),
            $this->bus,
            new NullLogger(),
        );
        $handler = new NotifyInboxOwnerHandler(
            $asks,
            $items,
            new InboxOpenPushTags($asks, $items),
            $notifier,
            $container->get(UrlGeneratorInterface::class),
            $container->get(TranslatorInterface::class),
            new ReferenceTokens(),
            (string) $container->getParameter('app.url'),
            'en',
        );

        $handler($message);
    }
}
