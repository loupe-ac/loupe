<?php

declare(strict_types=1);

namespace App\Tests\DevSeed;

use App\DevSeed\DevSeedId;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Service\Dev\DevBoardSeeder;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('dev-seed-restore')]
final class DevSeedRestoreBoardTest extends DevSeedTestCase
{
    public function test_the_pull_request_review_points_at_the_link_on_a_first_run_and_after_a_restored_card(): void
    {
        $this->seed(self::DAY, ['--keep-flag-defaults' => true]);
        $counts = $this->rowCounts();
        $connection = $this->em()->getConnection();
        $linkedReview = \sprintf("SELECT pull_request_id FROM inbox_reviews WHERE item_id = '%s'", DevSeedId::of('inbox/item/pull-request'));
        $first = $connection->fetchOne($linkedReview);
        self::assertIsString($first, 'The first run left the pull request review with no link.');

        $em = $this->em();
        $em->clear();
        $card = $em->find(Card::class, DevSeedId::of('board/card/checkout')) ?? throw new \LogicException('The card is missing.');
        self::getContainer()->get(DeleteCardHandler::class)(new DeleteCardCommand($card, Actor::Human));
        self::assertFalse(\is_string($connection->fetchOne($linkedReview)), 'The deleted card kept the link of the review.');

        $this->seed(self::DAY, ['--keep-flag-defaults' => true]);

        self::assertIsString($connection->fetchOne($linkedReview), 'The restored card did not go back on the review.');
        self::assertSame($counts['inbox_item_cards'], $this->rowCounts()['inbox_item_cards'], 'The restored card did not go back on its items.');
    }

    /** @return iterable<string, array{string}> */
    public static function partialBoardDeletions(): iterable
    {
        yield 'the link of the checkout card' => ["DELETE FROM board_card_pull_requests WHERE url = 'https://github.com/example/atlas/pull/438'"];
        yield 'one of the two links of a card' => ["DELETE FROM board_card_pull_requests WHERE url = 'https://github.com/example/atlas/pull/449'"];
        yield 'the link of the outdated approval' => ["DELETE FROM board_card_pull_requests WHERE url = 'https://github.com/example/atlas/pull/457'"];
        yield 'one link of an experiment card' => ["DELETE FROM board_card_pull_requests WHERE id = (SELECT id FROM board_card_pull_requests WHERE repository = 'example/experiment' LIMIT 1)"];
        yield 'every link' => ['DELETE FROM board_card_pull_requests'];
        yield 'one state' => ["DELETE FROM forge_pull_requests WHERE repository = 'example/atlas' AND number = 441"];
        yield 'every state' => ['DELETE FROM forge_pull_requests'];
        yield 'every pause' => ['DELETE FROM card_pauses'];
    }

    #[DataProvider('partialBoardDeletions')]
    public function test_a_later_run_restores_each_pull_request_link_state_and_pause_while_the_card_remains(string $deletion): void
    {
        $this->seed(self::DAY);
        $counts = $this->rowCounts();
        $connection = $this->em()->getConnection();
        self::assertGreaterThan(0, $connection->executeStatement($deletion));
        self::assertNotSame($counts, $this->rowCounts());

        $this->seed(self::DAY);

        self::assertSame($counts, $this->rowCounts());
        self::assertIsString($connection->fetchOne(\sprintf("SELECT pull_request_id FROM inbox_reviews WHERE item_id = '%s'", DevSeedId::of('inbox/item/pull-request'))), 'The review of the pull request item has no link.');
        self::assertSame(
            1,
            (int) $connection->fetchOne("SELECT COUNT(*) FROM forge_pull_requests WHERE repository = 'example/atlas' AND number = 457 AND approval_id = 'atlas-review-457' AND ready_to_merge = false"),
            'The state of the outdated approval lost its approval.',
        );
    }

    public function test_a_restored_pull_request_link_goes_back_on_its_review_while_an_older_link_remains(): void
    {
        $this->seed(self::DAY);
        $em = $this->em();
        $checkout = $em->find(Card::class, DevSeedId::of('board/card/checkout')) ?? throw new \LogicException('The card is missing.');
        $em->persist(new CardPullRequest($checkout, 'https://github.com/example/atlas/pull/1', addedAt: new \DateTimeImmutable('2020-01-01')));
        $em->flush();
        $connection = $em->getConnection();
        self::assertSame(1, $connection->executeStatement('DELETE FROM board_card_pull_requests WHERE url = ?', [DevBoardSeeder::pullRequestUrl(438)]));
        $em->clear();

        $this->seed(self::DAY);

        self::assertSame(DevBoardSeeder::pullRequestUrl(438), $connection->fetchOne(
            'SELECT link.url FROM inbox_reviews review JOIN board_card_pull_requests link ON link.id = review.pull_request_id WHERE review.item_id = ?',
            [(string) DevSeedId::of('inbox/item/pull-request')],
        ));
    }

    public function test_a_later_run_restores_the_handoff_link_while_the_card_holds_another_document(): void
    {
        $this->seed(self::DAY);
        $em = $this->em();
        $onboarding = $em->find(Card::class, DevSeedId::of('board/card/onboarding')) ?? throw new \LogicException('The card is missing.');
        $other = $em->find(Document::class, DevSeedId::of('review/document/column-rules')) ?? throw new \LogicException('The document is missing.');
        $em->persist(new CardDocument($onboarding, $other));
        $em->flush();
        $connection = $em->getConnection();
        $handoffLink = 'SELECT COUNT(*) FROM board_card_documents WHERE card_id = ? AND document_id = ?';
        $handoffKeys = [(string) $onboarding->id, (string) DevSeedId::of('review/document/first-handoff')];
        self::assertSame(1, $connection->executeStatement('DELETE FROM board_card_documents WHERE card_id = ? AND document_id = ?', $handoffKeys));
        $em->clear();

        $this->seed(self::DAY);

        self::assertSame(1, (int) $connection->fetchOne($handoffLink, $handoffKeys));
    }
}
