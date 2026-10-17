<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Service\CardCommentLinkResolver;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardCommentLinkResolverTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardCommentLinkResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(CardCommentLinkResolver::class);
        self::assertInstanceOf(CardCommentLinkResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    public function test_it_links_each_comment_to_its_card(): void
    {
        [$project, $card, $comment] = $this->linkedComment('link-ok');
        $unlinked = new SiteReviewComment($project, 1, 'No card', 'https://example.com');
        $this->em->persist($unlinked);
        $this->em->flush();

        $links = $this->resolver->resolve([$comment, $unlinked], $project);

        self::assertSame([(string) $comment->id], array_keys($links));
        self::assertSame('#4', $links[(string) $comment->id]->label);
        self::assertStringEndsWith(
            \sprintf('/projects/%s/board/cards/%s', $project->id, $card->id),
            (string) $links[(string) $comment->id]->url,
        );
    }

    public function test_a_card_of_another_project_links_nothing(): void
    {
        [, , $comment] = $this->linkedComment('link-foreign');
        [$other] = $this->linkedComment('link-other');

        self::assertSame([], $this->resolver->resolve([$comment], $other));
        self::assertSame([], $this->resolver->resolve([], $other));
    }

    /** @return array{Project, Card, SiteReviewComment} */
    private function linkedComment(string $slug): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug);
        $this->em->persist($project);
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', 'body', 4);
        $this->em->persist($card);
        $comment = new SiteReviewComment($project, 0, 'A note', 'https://example.com');
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));
        $this->em->flush();

        return [$project, $card, $comment];
    }
}
