<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Artifact\Security\ArtifactTopicAuthorizer;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class ArtifactTopicAuthorizerTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_a_viewer_of_the_project_may_subscribe(): void
    {
        self::bootKernel();
        $project = $this->switchedOnProject('artifact-topic-owner@example.com');
        $this->signIn($project->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forArtifacts($project->id ?? throw new \LogicException('No id.'))));
    }

    public function test_a_stranger_is_refused(): void
    {
        self::bootKernel();
        $project = $this->switchedOnProject('artifact-topic-owned@example.com');
        $stranger = $this->project($this->em(), 'artifact-topic-stranger@example.com', 'Other');
        $this->signIn($stranger->owner);
        $topic = $this->topics()->forArtifacts($project->id ?? throw new \LogicException('No id.'));

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($topic));
        // Guard: the stranger passes on their own project, so the refusal is about the project.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forArtifacts($stranger->id ?? throw new \LogicException('No id.'))));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::bootKernel();
        $project = $this->switchedOnProject('artifact-topic-anon@example.com');

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forArtifacts($project->id ?? throw new \LogicException('No id.'))));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        self::bootKernel();
        $project = $this->switchedOnProject('artifact-topic-missing@example.com');
        $this->signIn($project->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forArtifacts(Uuid::v7())));
    }

    public function test_a_topic_of_another_kind_is_not_its_concern(): void
    {
        self::bootKernel();

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forBoard(Uuid::v7())));
    }

    private function authorizer(): ArtifactTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(ArtifactTopicAuthorizer::class);
        self::assertInstanceOf(ArtifactTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    private function signIn(\App\Module\Account\Entity\User $user): void
    {
        $storage = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
