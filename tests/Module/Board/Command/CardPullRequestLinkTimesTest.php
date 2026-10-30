<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A card update keeps the time and the flag of a link it does not change. */
final class CardPullRequestLinkTimesTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private const string KEPT = 'https://github.com/acme/app/pull/1';
    private const string DROPPED = 'https://github.com/acme/app/pull/2';
    private const string ADDED = 'https://github.com/acme/app/pull/3';

    private EntityManagerInterface $em;
    private Connection $connection;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $this->updateCard = $updateCard;

        $owner = new User(fullName: 'Riley', email: 'link-times-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_a_link_made_by_this_code_is_not_flagged(): void
    {
        $card = $this->create([self::KEPT]);

        self::assertFalse($this->linkOf($card, self::KEPT)->predatesLinkTimes);
        self::assertSame([false], $this->storedFlags($card));
    }

    public function test_an_unchanged_url_keeps_its_row_time_and_flag_and_a_removed_url_goes(): void
    {
        $card = $this->create([self::KEPT, self::DROPPED]);
        $this->connection->executeStatement(
            "UPDATE board_card_pull_requests SET added_at = '2026-01-01 10:00:00', predates_link_times = true WHERE card_id = :card AND url = :url",
            ['card' => $card->id?->toRfc4122(), 'url' => self::KEPT],
        );
        $keptId = $this->linkOf($card, self::KEPT)->id;
        $this->em->clear();

        $card = $this->em->find(Card::class, $card->id) ?? self::fail('card');
        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: Actor::Agent, pullRequestUrls: [self::KEPT, self::ADDED]));
        $this->em->clear();

        $card = $this->em->find(Card::class, $card->id) ?? self::fail('card');
        self::assertSame([self::KEPT, self::ADDED], $this->urls($card));

        $kept = $this->linkOf($card, self::KEPT);
        self::assertTrue($keptId?->equals($kept->id));
        self::assertSame('2026-01-01 10:00:00', $kept->addedAt->format('Y-m-d H:i:s'));
        self::assertTrue($kept->predatesLinkTimes);
        self::assertFalse($this->linkOf($card, self::ADDED)->predatesLinkTimes);
    }

    public function test_an_empty_list_clears_every_link(): void
    {
        $card = $this->create([self::KEPT, self::DROPPED]);

        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: Actor::Agent, pullRequestUrls: []));
        $this->em->clear();

        $card = $this->em->find(Card::class, $card->id) ?? self::fail('card');
        self::assertSame([], $this->urls($card));
    }

    public function test_rows_written_by_old_code_read_as_flagged(): void
    {
        $card = $this->create([self::KEPT]);
        $cardId = $card->id?->toRfc4122();
        $this->connection->executeStatement('DELETE FROM board_card_pull_requests WHERE card_id = :card', ['card' => $cardId]);
        $this->connection->executeStatement(
            "INSERT INTO board_card_pull_requests (id, url, forge, repository, number, added_at, card_id) VALUES (:id, :url, 'github', 'acme/app', 1, :at, :card)",
            ['id' => '0198a000-0000-7000-8000-000000000001', 'url' => self::KEPT, 'at' => '2026-02-02 12:00:00', 'card' => $cardId],
        );
        $this->em->clear();

        $card = $this->em->find(Card::class, $card->id) ?? self::fail('card');

        self::assertTrue($this->linkOf($card, self::KEPT)->predatesLinkTimes);
    }

    /** @param list<string> $urls */
    private function create(array $urls): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: 'Body',
            type: 'feature',
            pullRequestUrls: $urls,
        ));
    }

    private function linkOf(Card $card, string $url): CardPullRequest
    {
        foreach ($card->pullRequests as $link) {
            if ($link->url === $url) {
                return $link;
            }
        }

        self::fail('No link '.$url);
    }

    /** @return list<string> */
    private function urls(Card $card): array
    {
        return array_values(array_map(static fn (CardPullRequest $link): string => $link->url, $card->pullRequests->toArray()));
    }

    /** @return list<bool> */
    private function storedFlags(Card $card): array
    {
        $flags = $this->connection->fetchFirstColumn('SELECT predates_link_times FROM board_card_pull_requests WHERE card_id = :card', ['card' => $card->id?->toRfc4122()]);

        return array_map(static fn (mixed $flag): bool => (bool) $flag, $flags);
    }
}
