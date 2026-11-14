<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Account\Security\AccountTopicAuthorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class AccountTopicAuthorizerTest extends KernelTestCase
{
    public function test_the_user_the_topic_names_may_subscribe(): void
    {
        $owner = $this->user('account-topic-owner@example.com');
        $this->signIn($owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($owner)));
    }

    public function test_another_user_is_refused(): void
    {
        $owner = $this->user('account-topic-named@example.com');
        $stranger = $this->user('account-topic-stranger@example.com');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($owner)));
        // Guard: the stranger passes on a topic of their own, so the refusal is about the user.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($stranger)));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::bootKernel();
        $owner = $this->user('account-topic-anonymous@example.com');

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($owner)));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $owner = $this->user('account-topic-other@example.com');
        $this->signIn($owner);
        $ownerId = $owner->id ?? throw new \LogicException('The owner has no id.');
        $userTopics = $this->service(UserTopicBuilder::class);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forUser($ownerId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forInbox($ownerId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forNotifications($ownerId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->service(ProjectTopicBuilder::class)->forBoard(Uuid::v7())));
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        self::bootKernel();
        $em = $this->service(EntityManagerInterface::class);
        $user = new User(fullName: 'Topic User', email: $email);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function signIn(User $user): void
    {
        $tokens = $this->service('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function topic(User $user): string
    {
        return $this->service(UserTopicBuilder::class)->forAccount($user->id ?? throw new \LogicException('The user has no id.'));
    }

    private function authorizer(): AccountTopicAuthorizer
    {
        return $this->service(AccountTopicAuthorizer::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    private function service(string $id): object
    {
        return self::getContainer()->get($id) ?? throw new \LogicException('No service '.$id);
    }
}
