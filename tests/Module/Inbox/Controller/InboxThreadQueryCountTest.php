<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

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

    public function test_the_card_page_reads_the_same_for_one_item_and_for_five(): void
    {
        $this->linkedItems(1, 1);
        $this->warmUp('/projects/'.$this->project->id.'/board/cards/'.$this->card->id);
        $small = $this->statementsOf('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, 1, '[data-inbox-linked] [data-inbox-item]');

        $this->linkedItems(2, 5);
        $big = $this->statementsOf('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, 5, '[data-inbox-linked] [data-inbox-item]');

        self::assertSame(\count($small), \count($big), "One item:\n".implode("\n", $small)."\n\nFive items:\n".implode("\n", $big));
    }

    public function test_the_inbox_detail_reads_the_same_for_an_ask_of_one_item_and_of_five(): void
    {
        $one = $this->askHolding($this->em, $this->project, [$this->question($this->em, $this->project, 1)]);
        $items = [];
        for ($number = 2; $number <= 6; ++$number) {
            $items[] = 0 === $number % 2 ? $this->todo($this->em, $this->project, $number) : $this->question($this->em, $this->project, $number);
        }
        $five = $this->askHolding($this->em, $this->project, $items);

        $this->warmUp('/projects/'.$this->project->id.'/inbox?ask='.$one->id);
        $small = $this->statementsOf('/projects/'.$this->project->id.'/inbox?ask='.$one->id, 1, '[data-inbox-item]');
        $big = $this->statementsOf('/projects/'.$this->project->id.'/inbox?ask='.$five->id, 5, '[data-inbox-item]');

        self::assertSame(\count($small), \count($big), "One item:\n".implode("\n", $small)."\n\nFive items:\n".implode("\n", $big));
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
            $this->askHolding($this->em, $this->project, [$item]);
            $item->cards->add(new InboxItemCard($item, $this->card));
            $this->em->flush();
        }
    }

    /** The first request of a test reads caches that later ones find warm, so it would skew the comparison. */
    private function warmUp(string $url): void
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
    }

    /**
     * The read statements of one GET, after a guard that the page showed the expected items.
     *
     * @return list<string>
     */
    private function statementsOf(string $url, int $expectedItems, string $itemSelector): array
    {
        $this->em->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual($expectedItems, $crawler->filter($itemSelector)->count());
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
