<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Security;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Insights\Security\DashboardTopicAuthorizer;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class DashboardTopicAuthorizerTest extends KernelTestCase
{
    use InsightsScenario;

    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->scenarioProject('dashboard-topic');
        $this->owner = $this->project->owner;
    }

    public function test_the_owner_may_subscribe(): void
    {
        $this->signIn($this->owner);

        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project)));
    }

    public function test_a_stranger_is_refused_on_this_project_and_allowed_on_his_own(): void
    {
        $stranger = $this->user($this->em(), 'dashboard-topic-stranger@example.com');
        $this->signIn($stranger);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project)));
        // Guard: the same stranger passes on a project of his own, so the refusal is about the project.
        self::assertTrue($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project($this->em(), $stranger, 'Theirs'))));
    }

    public function test_nobody_signed_in_is_refused(): void
    {
        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project)));
    }

    public function test_a_project_that_does_not_exist_is_refused(): void
    {
        $this->signIn($this->owner);

        self::assertFalse($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forDashboard(Uuid::v7())));
    }

    public function test_it_claims_no_other_topic(): void
    {
        $this->signIn($this->owner);
        $projectId = $this->project->requireId();

        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forBoard($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topics()->forWorkerRuns($projectId)));
        self::assertNull($this->authorizer()->mayCurrentUserSubscribe($this->topic($this->project).'/extra'));
    }

    private function signIn(User $user): void
    {
        $tokens = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function topic(Project $project): string
    {
        return $this->topics()->forDashboard($project->requireId());
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }

    private function authorizer(): DashboardTopicAuthorizer
    {
        $authorizer = self::getContainer()->get(DashboardTopicAuthorizer::class);
        self::assertInstanceOf(DashboardTopicAuthorizer::class, $authorizer);

        return $authorizer;
    }
}
