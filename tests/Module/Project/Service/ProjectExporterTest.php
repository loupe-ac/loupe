<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Entity\ProjectMilestone;
use App\Module\Project\Repository\ProjectMilestoneRepository;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\ProjectExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectExporterTest extends TestCase
{
    public function test_exports_owned_projects(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($user, 'My project', 'example.com', description: 'Review the customer portal.');
        $project->forwardsToAgent = true;
        $project->allowedOrigins = ['https://staging.example.com'];
        $project->readinessGuideHiddenAt = new \DateTimeImmutable('2026-10-06T19:50:00+00:00');
        $project->agentGitHubLogin = 'acme-agent';
        $project->setUp = true;
        new \ReflectionProperty(Project::class, 'id')->setValue($project, Uuid::v7());
        $other = new Project($user, 'Not set up');
        new \ReflectionProperty(Project::class, 'id')->setValue($other, Uuid::v7());

        /** @var ProjectRepository&Stub $repo */
        $repo = $this->createStub(ProjectRepository::class);
        $repo->method('findByOwner')->willReturn([$project, $other]);
        /** @var ProjectMilestoneRepository&Stub $milestones */
        $milestones = $this->createStub(ProjectMilestoneRepository::class);
        $milestones->method('findOfOwner')->willReturn([new ProjectMilestone($project, 'init-finished', new \DateTimeImmutable('2026-10-07T10:00:00+00:00'))]);

        $rows = iterator_to_array(new ProjectExporter($repo, $milestones)->export($user));

        self::assertCount(2, $rows);
        self::assertTrue($rows[0]['setUp']);
        self::assertSame([['key' => 'init-finished', 'reachedAt' => '2026-10-07T10:00:00+00:00']], $rows[0]['milestones']);
        self::assertFalse($rows[1]['setUp']);
        self::assertSame([], $rows[1]['milestones']);
        self::assertSame('My project', $rows[0]['name']);
        self::assertSame('Review the customer portal.', $rows[0]['description']);
        self::assertSame('example.com', $rows[0]['domain']);
        self::assertSame('english', $rows[0]['searchLanguage']);
        self::assertTrue($rows[0]['forwardsToAgent']);
        self::assertSame(['https://staging.example.com'], $rows[0]['allowedOrigins']);
        self::assertArrayHasKey('createdAt', $rows[0]);
        self::assertSame('2026-10-06T19:50:00+00:00', $rows[0]['readinessGuideHiddenAt']);
        self::assertArrayHasKey('agentFirstSeenAt', $rows[0]);
        self::assertNull($rows[0]['agentFirstSeenAt']);
        self::assertSame('acme-agent', $rows[0]['agentGitHubLogin']);
        self::assertSame('projects.json', new ProjectExporter($repo, $milestones)->filename());
    }
}
