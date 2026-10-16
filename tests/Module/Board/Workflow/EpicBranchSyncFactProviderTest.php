<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\EpicBranchSyncFactProvider;
use App\Module\Board\Workflow\EpicBranchSyncFacts;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EpicBranchSyncFactProviderTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->project = $this->makeProject('epic-branch-sync');
    }

    public function test_a_card_with_no_parent_is_synced_whatever_blocks_it(): void
    {
        $card = $this->card();
        $this->blocks($this->card('done', completedAt: '2026-10-02 10:00:00'), $card);
        $this->em->flush();

        self::assertTrue($this->synced($card));
    }

    public function test_a_child_with_no_done_blocker_outside_the_epic_is_synced(): void
    {
        $epic = $this->card();
        $child = $this->card(parent: $epic);
        $this->blocks($this->card('in-progress'), $child);
        $this->blocks($this->card('done', parent: $epic, completedAt: '2026-10-02 10:00:00'), $child);
        $this->em->flush();

        self::assertTrue($this->synced($child));
    }

    public function test_a_done_blocker_outside_the_epic_leaves_the_branch_unsynced_until_a_later_merge(): void
    {
        $epic = $this->card();
        $child = $this->card(parent: $epic);
        $this->blocks($this->card('done', completedAt: '2026-10-02 10:00:00'), $child);
        $this->em->flush();

        self::assertFalse($this->synced($child));

        $epic->epicBranchSyncedAt = new \DateTimeImmutable('2026-10-02 09:00:00');
        $this->em->flush();
        self::assertFalse($this->synced($child));

        $epic->epicBranchSyncedAt = new \DateTimeImmutable('2026-10-02 11:00:00');
        $this->em->flush();
        self::assertTrue($this->synced($child));
    }

    public function test_a_blocker_that_finishes_after_the_merge_makes_the_branch_unsynced_again(): void
    {
        $epic = $this->card();
        $epic->epicBranchSyncedAt = new \DateTimeImmutable('2026-10-02 11:00:00');
        $child = $this->card(parent: $epic);
        $this->blocks($this->card('done', completedAt: '2026-10-02 10:00:00'), $child);
        $this->blocks($this->card('done', completedAt: '2026-10-02 12:00:00'), $child);
        $this->em->flush();

        self::assertFalse($this->synced($child));
    }

    public function test_the_repository_lists_the_done_blockers_outside_the_epic_only(): void
    {
        $epic = $this->card();
        $child = $this->card(parent: $epic);
        $outside = $this->card('done', completedAt: '2026-10-02 10:00:00');
        $otherEpic = $this->card();
        $outsideChild = $this->card('done', parent: $otherEpic, completedAt: '2026-10-02 10:00:00');
        $this->blocks($outside, $child);
        $this->blocks($outsideChild, $child);
        $this->blocks($this->card('done', parent: $epic, completedAt: '2026-10-02 10:00:00'), $child);
        $this->blocks($this->card('in-progress'), $child);
        $this->em->flush();

        $repository = self::getContainer()->get(CardRepository::class);
        self::assertInstanceOf(CardRepository::class, $repository);

        self::assertSame([$outside, $outsideChild], $repository->findDoneBlockersOutsideEpicOf($child));
    }

    public function test_the_fingerprint_is_the_synced_flag(): void
    {
        self::assertTrue($this->provider()->fingerprint(new EpicBranchSyncFacts(true)));
        self::assertFalse($this->provider()->fingerprint(new EpicBranchSyncFacts(false)));
    }

    private function synced(Card $card): bool
    {
        $facts = $this->provider()->build($card->snapshot());
        self::assertInstanceOf(EpicBranchSyncFacts::class, $facts);

        return $facts->synced;
    }

    private function card(string $column = 'in-progress', ?Card $parent = null, ?string $completedAt = null): Card
    {
        $card = new Card($this->project, $this->column($this->project, $column), 'Card', '', ++$this->number);
        $card->parent = $parent;
        $card->completedAt = null === $completedAt ? null : new \DateTimeImmutable($completedAt);
        $this->em->persist($card);

        return $card;
    }

    private function blocks(Card $blocker, Card $blocked): void
    {
        $this->em->persist(new CardLink($blocker, $blocked, CardLinkKind::Blocks));
    }

    private function provider(): EpicBranchSyncFactProvider
    {
        $provider = self::getContainer()->get(EpicBranchSyncFactProvider::class);
        self::assertInstanceOf(EpicBranchSyncFactProvider::class, $provider);

        return $provider;
    }
}
