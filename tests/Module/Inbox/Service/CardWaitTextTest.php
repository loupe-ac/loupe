<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\CardWaitText;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The item title and body of a document wait whose document no longer exists. */
final class CardWaitTextTest extends KernelTestCase
{
    use InboxFixtures;

    private CardWaitText $text;
    private InboxCardWait $wait;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $text = self::getContainer()->get(CardWaitText::class);
        self::assertInstanceOf(CardWaitText::class, $text);
        $this->text = $text;

        $project = $this->project($em, $this->owner($em, 'wait-text'), 'wait-text');
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#4 Ship it', blocking: true);
        $watch = new InboxCardWatch($item, Uuid::v7(), 4);
        $this->wait = new InboxCardWait($watch, InboxCardWaitTrigger::DocumentInReview, InboxCardWaitType::Document, InboxCardWaitReason::WaitingForReview, documentId: Uuid::v7(), versionNumber: 1);
        $watch->waits->add($this->wait);
    }

    public function test_the_title_of_a_deleted_document_wait_names_its_version(): void
    {
        self::assertSame('#4 Deleted document version 1', $this->text->title([$this->wait], 4, 'Ship it', 200));
    }

    public function test_the_body_of_a_deleted_document_wait_names_no_empty_title(): void
    {
        $body = $this->text->body([$this->wait]);

        self::assertSame('A deleted document, version 1, of card 4 waited for your review. Dismiss this item.', $body);
        self::assertStringNotContainsString('""', $body);
    }
}
