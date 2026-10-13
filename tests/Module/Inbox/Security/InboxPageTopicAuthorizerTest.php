<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Security\InboxPageTopicAuthorizer;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class InboxPageTopicAuthorizerTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->owner($em, 'inbox-page-topic-owner');
        $this->project = $this->project($em, $this->owner, 'inbox-page-topic');
        $em->flush();
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
    }

    public function test_a_viewer_of_the_project_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->pageTopic($this->project)));
    }

    public function test_a_stranger_is_refused(): void
    {
        $stranger = $this->owner($this->em, 'inbox-page-topic-stranger');
        $strangerProject = $this->project($this->em, $stranger, 'inbox-page-topic-stranger');
        $this->em->flush();
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->pageTopic($this->project)));
        // Guard: the stranger passes on a project of their own, so the refusal is about the project.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->pageTopic($strangerProject)));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->pageTopic($this->project)));
    }

    public function test_a_viewer_is_refused_while_the_inbox_is_off(): void
    {
        $this->signIn($this->owner);
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->pageTopic($this->project)));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        $this->signIn($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forInbox(Uuid::v7())));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $projectId = $this->project->id ?? throw new \LogicException('The project has no id.');
        $ownerId = $this->owner->id ?? throw new \LogicException('The owner has no id.');
        $userTopics = self::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $userTopics);

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forBoard($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forActivity($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forInbox($projectId).'/extra'));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($userTopics->forInbox($ownerId)));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function pageTopic(Project $project): string
    {
        return $this->topics()->forInbox($project->id ?? throw new \LogicException('The project has no id.'));
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    private function authorizer(): InboxPageTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(InboxPageTopicAuthorizer::class);
        self::assertInstanceOf(InboxPageTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }
}
