<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Notification\Security\NotificationTopicAuthorizer;
use App\Tests\Module\Notification\NotificationScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class NotificationTopicAuthorizerTest extends KernelTestCase
{
    use NotificationScenario;

    public function test_the_user_the_topic_names_may_subscribe(): void
    {
        $owner = $this->makeUser(static::getContainer(), 'topic-owner@example.com');
        $this->signIn($owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($owner)));
    }

    public function test_another_user_is_refused(): void
    {
        $owner = $this->makeUser(static::getContainer(), 'topic-named@example.com');
        $stranger = $this->makeUser(static::getContainer(), 'topic-stranger@example.com');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($owner)));
        // Guard: the stranger passes on a topic of their own, so the refusal is about the user.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($stranger)));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        $owner = $this->makeUser(static::getContainer(), 'topic-anonymous@example.com');

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($owner)));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $owner = $this->makeUser(static::getContainer(), 'topic-other@example.com');
        $this->signIn($owner);
        $ownerId = $owner->id ?? throw new \LogicException('The owner has no id.');
        $userTopics = $this->service(UserTopicBuilder::class);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forUser($ownerId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forInbox($ownerId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->service(ProjectTopicBuilder::class)->forBoard(Uuid::v7())));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function topic(User $user): string
    {
        return $this->service(UserTopicBuilder::class)->forNotifications($user->id ?? throw new \LogicException('The user has no id.'));
    }

    private function authorizer(): NotificationTopicAuthorizer
    {
        return $this->service(NotificationTopicAuthorizer::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
