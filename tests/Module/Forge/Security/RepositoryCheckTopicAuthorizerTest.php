<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Forge\Security\RepositoryCheckTopicAuthorizer;
use App\Module\Forge\Service\RepositoryCheckTopics;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class RepositoryCheckTopicAuthorizerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->user('topic-owner');
        $this->project = new Project($this->owner, 'repository-check-topic-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_the_owner_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project->id)));
    }

    public function test_a_stranger_is_refused_while_the_owner_passes_on_the_same_topic(): void
    {
        $stranger = $this->user('topic-stranger');
        $this->signIn($stranger);
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project->id)));

        $this->signIn($this->owner);
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project->id)));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project->id)));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        $this->signIn($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic(Uuid::v7())));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $projectId = $this->project->id ?? throw new \LogicException('The project has no id.');
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($topics->forBoard($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($topics->forInbox($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topic($projectId).'/extra'));
    }

    private function user(string $label): User
    {
        $user = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        AcceptedTerms::stamp($user, self::getContainer());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function topic(?Uuid $projectId): string
    {
        $topics = self::getContainer()->get(RepositoryCheckTopics::class);
        self::assertInstanceOf(RepositoryCheckTopics::class, $topics);

        return $topics->forProject($projectId ?? throw new \LogicException('The project has no id.'));
    }

    private function authorizer(): RepositoryCheckTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(RepositoryCheckTopicAuthorizer::class);
        self::assertInstanceOf(RepositoryCheckTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }
}
