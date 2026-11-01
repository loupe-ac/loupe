<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\OpenRouter\Security\OpenRouterTopicAuthorizer;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class OpenRouterTopicAuthorizerTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_the_user_the_topic_names_may_subscribe(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'topic-owner');
        $this->signIn($owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->userTopic($owner)));
    }

    public function test_another_user_is_refused_the_user_topic(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'topic-named');
        $stranger = $this->makeUser(self::getContainer(), 'topic-stranger');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->userTopic($owner)));
        // Guard: the stranger passes on a topic of their own, so the refusal is about the user.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->userTopic($stranger)));
    }

    public function test_the_owner_of_the_project_may_subscribe_to_its_topic(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'project-topic-owner');
        $project = $this->makeProject(self::getContainer(), $owner);
        $this->signIn($owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->projectTopic($project->id)));
    }

    public function test_another_user_is_refused_the_project_topic(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'project-topic-named');
        $stranger = $this->makeUser(self::getContainer(), 'project-topic-stranger');
        $project = $this->makeProject(self::getContainer(), $owner);

        $this->signIn($owner);
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->projectTopic($project->id)));
        $this->signIn($stranger);
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->projectTopic($project->id)));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        self::bootKernel();
        $this->signIn($this->makeUser(self::getContainer(), 'project-topic-missing'));

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->projectTopic(Uuid::v7())));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'topic-anonymous');
        $project = $this->makeProject(self::getContainer(), $owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->userTopic($owner)));
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->projectTopic($project->id)));
    }

    public function test_it_claims_no_other_topic(): void
    {
        self::bootKernel();
        $owner = $this->makeUser(self::getContainer(), 'topic-other');
        $project = $this->makeProject(self::getContainer(), $owner);
        $this->signIn($owner);
        $users = $this->userTopics();
        $projects = $this->projectTopics();
        self::assertNotNull($owner->id);
        self::assertNotNull($project->id);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($users->forNotifications($owner->id)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($users->forInbox($owner->id)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($users->forUser($owner->id)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($projects->forBoard($project->id)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe('https://elsewhere.test/anything'));
    }

    private function userTopic(User $user): string
    {
        return $this->userTopics()->forOpenRouter($user->id ?? throw new \LogicException('The user has no id.'));
    }

    private function projectTopic(?Uuid $projectId): string
    {
        return $this->projectTopics()->forOpenRouter($projectId ?? throw new \LogicException('The project has no id.'));
    }

    private function userTopics(): UserTopicBuilder
    {
        $builder = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $builder);

        return $builder;
    }

    private function projectTopics(): ProjectTopicBuilder
    {
        $builder = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $builder);

        return $builder;
    }

    private function signIn(User $user): void
    {
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function authorizer(): OpenRouterTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(OpenRouterTopicAuthorizer::class);
        self::assertInstanceOf(OpenRouterTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }
}
