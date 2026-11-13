<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\WorkSubject;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\WorkSubject\ProjectWorkSubjectHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectWorkSubjectHandlerTest extends TestCase
{
    public function test_it_owns_the_project_subject_type(): void
    {
        self::assertSame('project', ProjectWorkSubjectHandler::subjectType());
    }

    public function test_only_the_project_itself_is_a_subject_of_the_project(): void
    {
        $project = new Project(new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x'), 'Init');
        $id = Uuid::v7();
        new \ReflectionProperty(Project::class, 'id')->setValue($project, $id);
        $handler = new ProjectWorkSubjectHandler();

        self::assertTrue($handler->subjectExists($project, $id));
        self::assertFalse($handler->subjectExists($project, Uuid::v7()));
    }
}
