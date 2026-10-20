<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Security;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Security\CardFeedbackVoter;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

final class CardFeedbackVoterTest extends TestCase
{
    private CardFeedbackVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CardFeedbackVoter();
    }

    /** @return iterable<string, array{string}> */
    public static function attributes(): iterable
    {
        yield 'view' => [CardFeedbackVoter::VIEW];
        yield 'resolve' => [CardFeedbackVoter::RESOLVE];
        yield 'reopen' => [CardFeedbackVoter::REOPEN];
    }

    #[DataProvider('attributes')]
    public function test_the_owner_of_the_card_and_the_note_project_is_granted(string $attribute): void
    {
        $owner = $this->user('alice');
        $project = $this->project($owner);

        $result = $this->voter->vote($this->token($owner), $this->link($project, $project), [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[DataProvider('attributes')]
    public function test_another_user_is_denied(string $attribute): void
    {
        $project = $this->project($this->user('alice'));

        $result = $this->voter->vote($this->token($this->user('eve')), $this->link($project, $project), [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[DataProvider('attributes')]
    public function test_a_note_of_another_project_is_denied_to_the_owner(string $attribute): void
    {
        $owner = $this->user('alice');

        $result = $this->voter->vote($this->token($owner), $this->link($this->project($owner), $this->project($owner)), [$attribute]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_an_unrelated_attribute_is_abstained_on(): void
    {
        $owner = $this->user('alice');
        $project = $this->project($owner);

        $result = $this->voter->vote($this->token($owner), $this->link($project, $project), ['card_feedback.other']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    private function link(Project $cardProject, Project $noteProject): CardSiteReviewComment
    {
        $card = new Card(project: $cardProject, column: new BoardColumn(project: $cardProject, label: 'Backlog', slug: 'backlog', position: 0), title: 'Fix it', body: '', number: 1);

        return new CardSiteReviewComment($card, new SiteReviewComment($noteProject, 0, 'Note', null));
    }

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'p');
        new \ReflectionProperty(Project::class, 'id')->setValue($project, Uuid::v7());

        return $project;
    }

    private function user(string $name): User
    {
        return new User(fullName: ucfirst($name), email: $name.'@example.com', password: 'hashed');
    }

    private function token(User $user): TokenInterface&Stub
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
