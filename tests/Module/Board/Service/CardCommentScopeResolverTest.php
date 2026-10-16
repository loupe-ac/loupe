<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Service\CardCommentScopeResolver;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardCommentScopeResolverTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardCommentScopeResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(CardCommentScopeResolver::class);
        self::assertInstanceOf(CardCommentScopeResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    public function test_it_declines_a_scope_it_does_not_know(): void
    {
        [$project] = $this->projectWithCard('scope-unknown');

        self::assertNull($this->resolver->resolve('branch:feat/x', $project));
    }

    public function test_it_answers_the_notes_of_a_card_and_nothing_for_a_card_of_another_project(): void
    {
        [$project, $card, $note] = $this->projectWithCard('scope-card');
        [$other] = $this->projectWithCard('scope-card-other');

        $ids = $this->resolver->resolve('card:'.$card->id, $project);
        self::assertNotNull($ids);
        self::assertSame([(string) $note->id], array_map(strval(...), $ids));

        self::assertSame([], $this->resolver->resolve('card:'.$card->id, $other));
    }

    public function test_it_reads_the_first_fifty_cards_of_a_list_only(): void
    {
        [$project, $card, $note] = $this->projectWithCard('scope-fifty');
        $padding = array_fill(0, 50, '0199c0de-0000-7000-8000-0000000000ff');

        $ids = $this->resolver->resolve('cards:'.implode(',', [...$padding, (string) $card->id]), $project);
        self::assertSame([], $ids);

        $ids = $this->resolver->resolve('cards:'.implode(',', [(string) $card->id, ...$padding]), $project);
        self::assertNotNull($ids);
        self::assertSame([(string) $note->id], array_map(strval(...), $ids));
    }

    public function test_it_drops_a_closed_card_from_every_scope(): void
    {
        [$project, $open, $openNote] = $this->projectWithCard('scope-closed');
        $epic = new Card($project, $this->column($project, 'next'), 'Epic', 'body', 2, type: 'epic');
        $this->em->persist($epic);
        $open->parent = $epic;
        $closed = $this->cardWithNote($project, 3, 'done');
        $closed->parent = $epic;
        $this->em->flush();

        $expected = [(string) $openNote->id];
        foreach (['epic:'.$epic->id, 'cards:'.$open->id.','.$closed->id] as $scope) {
            $ids = $this->resolver->resolve($scope, $project);
            self::assertNotNull($ids);
            self::assertSame($expected, array_map(strval(...), $ids), $scope);
        }
        self::assertSame([], $this->resolver->resolve('card:'.$closed->id, $project));
    }

    private function cardWithNote(Project $project, int $number, string $column): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Closed card', 'body', $number);
        $this->em->persist($card);
        $note = new SiteReviewComment($project, $number, 'A closed note', 'https://app/y');
        $this->em->persist($note);
        $this->em->persist(new CardSiteReviewComment($card, $note));

        return $card;
    }

    /** @return array{Project, Card, SiteReviewComment} */
    private function projectWithCard(string $slug): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug);
        $this->em->persist($project);
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', 'body', 1);
        $this->em->persist($card);
        $note = new SiteReviewComment($project, 0, 'A note', 'https://app/x');
        $this->em->persist($note);
        $this->em->persist(new CardSiteReviewComment($card, $note));
        $this->em->flush();

        return [$project, $card, $note];
    }
}
