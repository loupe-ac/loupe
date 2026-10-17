<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Service\CardCommentLabelResolver;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardCommentLabelResolverTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardCommentLabelResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(CardCommentLabelResolver::class);
        self::assertInstanceOf(CardCommentLabelResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    public function test_it_names_the_card_of_a_comment(): void
    {
        [$project, $comment] = $this->linkedComment('label-ok');

        self::assertSame('#1 Footer overlaps the launcher', $this->resolver->resolve($this->idOf($comment), $project));
    }

    public function test_a_comment_of_another_project_or_with_no_card_names_nothing(): void
    {
        [, $comment] = $this->linkedComment('label-foreign');
        [$other] = $this->linkedComment('label-other');

        self::assertNull($this->resolver->resolve($this->idOf($comment), $other));
        self::assertNull($this->resolver->resolve(Uuid::v7(), $other));
    }

    private function idOf(SiteReviewComment $comment): Uuid
    {
        self::assertNotNull($comment->id);

        return $comment->id;
    }

    /** @return array{Project, SiteReviewComment} */
    private function linkedComment(string $slug): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug);
        $this->em->persist($project);
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', 'body', 1);
        $this->em->persist($card);
        $comment = new SiteReviewComment($project, 0, 'A note', 'https://example.com');
        $this->em->persist($comment);
        $this->em->persist(new CardSiteReviewComment($card, $comment));
        $this->em->flush();

        return [$project, $comment];
    }
}
