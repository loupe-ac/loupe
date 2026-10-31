<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Board\Service\CardOpenInboxItems;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardOpenInboxItemReaderTest extends KernelTestCase
{
    use InboxFixtures;

    public function test_it_maps_each_card_to_its_oldest_open_item_from_an_ask_or_a_card_wait(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $project = $this->project($em, $this->owner($em, 'open-items'), 'open-items');
        $asked = $this->card($em, $project, 1);
        $waiting = $this->card($em, $project, 2);
        $closed = $this->card($em, $project, 3);
        $plain = $this->card($em, $project, 4);

        $older = $this->item($em, $project, 1, 'Older');
        $newer = new InboxItem(project: $project, number: 2, kind: InboxItemKind::Question, title: 'Newer', blocking: true, origin: InboxAskOrigin::Agent, createdAt: new \DateTimeImmutable('+1 minute'));
        $em->persist($newer);
        $em->persist(new InboxItemCard($newer, $asked));
        $em->persist(new InboxItemCard($older, $asked));

        $wait = new InboxItem(project: $project, number: 3, kind: InboxItemKind::Wait, title: '#2 Ship it', blocking: true, origin: InboxAskOrigin::Loupe);
        $em->persist($wait);
        $em->flush();
        $em->persist(new InboxCardWatch($wait, $waiting->id ?? throw new \LogicException('Flushed.'), $waiting->number));

        $done = $this->item($em, $project, 4, 'Done');
        $done->state = InboxItemState::Resolved;
        $em->persist(new InboxItemCard($done, $closed));

        $other = $this->project($em, $this->owner($em, 'other-items'), 'other-items');
        $elsewhere = $this->item($em, $other, 1, 'Elsewhere');
        $em->persist(new InboxItemCard($elsewhere, $this->card($em, $other, 1)));
        $em->flush();

        $numbers = static::getContainer()->get(CardOpenInboxItems::class)->forProject($project);

        ksort($numbers);
        $expected = [(string) $asked->id => 1, (string) $waiting->id => 3];
        ksort($expected);
        self::assertSame($expected, $numbers);
        self::assertArrayNotHasKey((string) $closed->id, $numbers);
        self::assertArrayNotHasKey((string) $plain->id, $numbers);
    }

    public function test_it_reads_nothing_while_the_inbox_is_off(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);
        $project = $this->project($em, $this->owner($em, 'inbox-off'), 'inbox-off');
        $item = $this->item($em, $project);
        $em->persist(new InboxItemCard($item, $this->card($em, $project)));
        $em->flush();

        self::assertSame([], static::getContainer()->get(CardOpenInboxItems::class)->forProject($project));
    }
}
