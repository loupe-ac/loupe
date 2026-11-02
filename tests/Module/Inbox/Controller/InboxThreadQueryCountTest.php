<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Inbox\Entity\InboxAskOrigin;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

/** The threads of the card page and of the inbox detail read once for the page, whatever the item count. */
final class InboxThreadQueryCountTest extends WebTestCase
{
    use BoardColumnFixtures;
    use InboxScenario;

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
        $this->owner = $this->signedUpUser($em, 'inbox-thread-count');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->seedColumns($this->project);
        $this->card = new Card(project: $this->project, column: $this->column($this->project, 'backlog'), title: 'Ship the export', body: 'Body', number: 1);
        $em->persist($this->card);
        $em->flush();

        $this->setInboxFlag(true);
        $this->client->loginUser($this->owner);
    }

    public function test_the_card_page_reads_the_same_for_one_item_of_each_kind_and_for_five(): void
    {
        $this->linkedItems(1, 1);
        $this->warmUp('/projects/'.$this->project->id.'/board/cards/'.$this->card->id);
        $small = $this->statementsOf('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, 2, 1, '[data-inbox-linked] [data-inbox-item]');

        $this->linkedItems(2, 5);
        $big = $this->statementsOf('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, 10, 5, '[data-inbox-linked] [data-inbox-item]');

        self::assertSame(\count($small), \count($big), "One of each:\n".implode("\n", $small)."\n\nFive of each:\n".implode("\n", $big));
    }

    public function test_the_inbox_detail_reads_the_same_for_an_ask_of_one_item_of_each_kind_and_of_five(): void
    {
        $one = $this->askHolding($this->em, $this->project, [$this->question($this->em, $this->project, 1), $this->waitItem(1)]);
        $items = [];
        for ($number = 2; $number <= 6; ++$number) {
            $items[] = 0 === $number % 2 ? $this->todo($this->em, $this->project, $number) : $this->question($this->em, $this->project, $number);
            $items[] = $this->waitItem($number);
        }
        $five = $this->askHolding($this->em, $this->project, $items);

        $this->warmUp('/projects/'.$this->project->id.'/inbox?ask='.$one->id);
        $small = $this->statementsOf('/projects/'.$this->project->id.'/inbox?ask='.$one->id, 2, 1, '[data-inbox-item]');
        $big = $this->statementsOf('/projects/'.$this->project->id.'/inbox?ask='.$five->id, 10, 5, '[data-inbox-item]');

        self::assertSame(\count($small), \count($big), "One of each:\n".implode("\n", $small)."\n\nFive of each:\n".implode("\n", $big));
    }

    private function linkedItems(int $from, int $to): void
    {
        $project = $this->em->find(Project::class, $this->project->id);
        $card = $this->em->find(Card::class, $this->card->id);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Card::class, $card);
        $this->project = $project;
        $this->card = $card;
        for ($number = $from; $number <= $to; ++$number) {
            $item = 0 === $number % 2 ? $this->todo($this->em, $this->project, $number) : $this->question($this->em, $this->project, $number);
            $wait = $this->waitItem($number);
            $this->askHolding($this->em, $this->project, [$item]);
            $this->askHolding($this->em, $this->project, [$wait]);
            $item->cards->add(new InboxItemCard($item, $this->card));
            $wait->cards->add(new InboxItemCard($wait, $this->card));
            $this->em->flush();
        }
    }

    /**
     * A wait item with its watch and two open waits for each of a run, a document and a pull request:
     * one on a stored subject and one on a subject that is gone.
     * A card takes one open watch, so each item watches its own card, in a column that the stage work reads.
     */
    private function waitItem(int $seed): InboxItem
    {
        $column = $this->em->find(BoardColumn::class, $this->column($this->project, 'next')->id);
        self::assertInstanceOf(BoardColumn::class, $column);
        $item = new InboxItem(project: $this->project, number: 100 + $seed, kind: InboxItemKind::Wait, title: 'Waiting', blocking: true, origin: InboxAskOrigin::Loupe);
        $watched = new Card(project: $this->project, column: $column, title: 'Watched', body: 'Body', number: 100 + $seed);
        $document = new Document($this->project->owner, $this->project, 'Design '.$seed);
        $pullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', $seed);
        $this->em->persist($item);
        $this->em->persist($watched);
        $this->em->persist($document);
        $this->em->persist($pullRequest);
        $this->em->flush();
        $cardId = $watched->id ?? throw new \LogicException('Stored.');

        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId,
            cardNumber: $watched->number,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            output: 'Needs the API key.',
        );
        $this->em->persist($run);
        $watch = new InboxCardWatch($item, $cardId, $watched->number);
        $this->em->persist($watch);
        $this->em->flush();

        $watch->waits->add(new InboxCardWait($watch, 'run-needs-you', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: $run->id));
        $watch->waits->add(new InboxCardWait($watch, 'document-in-review', InboxCardWaitType::Document, 'marker.document_in_review', documentId: $document->id, versionNumber: 1));
        $watch->waits->add(new InboxCardWait($watch, 'pull-request-idle', InboxCardWaitType::PullRequest, 'marker.pull_request_idle', pullRequestId: $pullRequest->id, headSha: 'abc'));
        $watch->waits->add(new InboxCardWait($watch, 'run-gone', InboxCardWaitType::WorkerRun, 'marker.run_needs_you', runId: Uuid::v7()));
        $watch->waits->add(new InboxCardWait($watch, 'document-gone', InboxCardWaitType::Document, 'marker.document_in_review', documentId: Uuid::v7(), versionNumber: 1));
        $watch->waits->add(new InboxCardWait($watch, 'pull-request-gone', InboxCardWaitType::PullRequest, 'marker.pull_request_idle', pullRequestId: Uuid::v7(), headSha: 'abc'));
        $this->em->flush();

        return $item;
    }

    /** The first request of a test reads caches that later ones find warm, so it would skew the comparison. */
    private function warmUp(string $url): void
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
    }

    /**
     * The read statements of one GET, after a guard that the page showed the expected items and their waits.
     *
     * @return list<string>
     */
    private function statementsOf(string $url, int $expectedItems, int $expectedWaits, string $itemSelector): array
    {
        $this->em->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual($expectedItems, $crawler->filter($itemSelector)->count());
        self::assertGreaterThanOrEqual($expectedWaits, $crawler->filter($itemSelector.' [data-inbox-waits]')->count());
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $statements = [];
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (str_starts_with($sql, 'SELECT') && !str_contains($sql, 'CURRENT_DATABASE')) {
                    $statements[] = $sql;
                }
            }
        }
        self::assertNotEmpty($statements);

        return $statements;
    }
}
