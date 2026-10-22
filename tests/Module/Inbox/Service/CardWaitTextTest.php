<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Service\CardWaitText;
use App\Module\Inbox\Service\RunOutputText;
use App\Module\Review\Entity\Document;
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
        $this->wait = new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: Uuid::v7(), versionNumber: 1);
        $watch->waits->add($this->wait);
    }

    public function test_the_title_of_a_deleted_document_wait_names_its_version(): void
    {
        self::assertSame('Deleted document version 1', $this->text->title([$this->wait], 'Ship it', 200));
    }

    public function test_the_body_of_a_deleted_document_wait_names_no_empty_title(): void
    {
        $body = $this->text->body([$this->wait]);

        self::assertSame('A deleted document, version 1, of card 4 waited for your review. Dismiss this item.', $body);
        self::assertStringNotContainsString('""', $body);
    }

    public function test_the_stored_values_fill_the_label_of_the_marker(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: Uuid::v7(), headSha: 'abc123', params: ['%number%' => 7]);

        self::assertSame('Pull request #7 waits for your approval. Review pull request #7.', $this->text->body([$wait]));
        self::assertSame('Review pull request #7', $this->text->title([$wait], 'Ship it', 200));
    }

    public function test_the_title_of_a_pull_request_wait_names_the_stored_title(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $pullRequest = new ForgePullRequest($this->wait->watch->project, 'github', 'acme/widgets', 812);
        $pullRequest->title = 'Fix the board filter';
        $em->persist($pullRequest);
        $em->flush();
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, 'marker.waits_for_approval', pullRequestId: $pullRequest->id, headSha: 'abc123', params: ['%number%' => 812]);

        self::assertSame('Review pull request #812: Fix the board filter', $this->text->title([$wait], 'Ship it', 200));
        self::assertSame('Pull request #812 waits for your approval. Review pull request #812.', $this->text->body([$wait]));
        self::assertSame('Review pull request #812: Fix th', $this->text->title([$wait], 'Ship it', 32));
    }

    public function test_the_title_of_a_document_wait_names_its_document_and_version(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $project = $this->wait->watch->project;
        $document = new Document($project->owner, $project, 'Tech design: Inbox asks');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $em->persist($document);
        $em->flush();
        $wait = new InboxCardWait($this->wait->watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: $document->id, versionNumber: 2);

        self::assertSame('Review "Tech design: Inbox asks", version 2', $this->text->title([$wait], 'Ship it', 200));
    }

    public function test_a_wait_with_no_label_reads_the_fallback_of_its_type(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'waits-for-approval', InboxCardWaitType::PullRequest, '', pullRequestId: Uuid::v7(), headSha: 'abc123', params: ['%number%' => 7]);

        self::assertSame('A pull request of card 4 waits for your review. Review pull request #7.', $this->text->body([$wait]));
    }

    public function test_a_run_wait_with_a_headline_reads_the_variant_of_its_label(): void
    {
        $params = ['%worker%' => 'implement worker', '%Worker%' => 'Implement worker', '%headline%' => 'Needs the API key'];
        $wait = new InboxCardWait($this->wait->watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7(), params: $params);

        self::assertSame('The implement worker on card 4 needs you: Needs the API key. Open card 4.', $this->text->body([$wait]));
        self::assertSame('Implement worker needs you: Needs the API key', $this->text->title([$wait], 'Ship it', 200));
    }

    public function test_a_run_wait_without_a_headline_reads_the_label_itself(): void
    {
        $wait = new InboxCardWait($this->wait->watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7());

        self::assertSame('The last worker run needs you. Open card 4.', $this->text->body([$wait]));
    }

    public function test_the_values_of_a_run_wait_come_from_the_output_of_the_run(): void
    {
        self::assertSame(
            ['%worker%' => 'implement worker', '%Worker%' => 'Implement worker', '%headline%' => 'Needs the API key'],
            RunOutputText::params('implement', 'STAGE RESULT: blocked: Needs the API key. Second line'),
        );
        self::assertSame(['%worker%' => 'worker', '%Worker%' => 'Worker', '%headline%' => ''], RunOutputText::params(null, ''));
    }
}
