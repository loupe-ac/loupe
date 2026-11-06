<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261106005306;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261106005306.php';

final class ForgePullRequestsReviewFromRulesMigrationTest extends KernelTestCase
{
    public function test_a_required_review_and_a_change_request_keep_the_review_gate_until_the_next_read(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Riley', email: 'forge-review-from-rules@example.com', password: 'x');
        $project = new Project($owner, 'forge-review-from-rules');
        $em->persist($owner);
        $em->persist($project);
        foreach ([PullRequestReview::Required, PullRequestReview::ChangesRequested, PullRequestReview::Approved, PullRequestReview::None] as $number => $review) {
            $pullRequest = new ForgePullRequest($project, 'github', 'acme/widgets', $number + 1);
            $pullRequest->review = $review;
            $em->persist($pullRequest);
        }
        $em->flush();
        $connection = $em->getConnection();
        $connection->executeStatement('ALTER TABLE forge_pull_requests DROP review_from_rules');

        $migration = new Version20261106005306($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame(
            [
                ['number' => 1, 'review_from_rules' => true],
                ['number' => 2, 'review_from_rules' => true],
                ['number' => 3, 'review_from_rules' => false],
                ['number' => 4, 'review_from_rules' => false],
            ],
            $connection->fetchAllAssociative(
                'SELECT number, review_from_rules FROM forge_pull_requests WHERE project_id = ? ORDER BY number',
                [(string) $project->id],
            ),
        );
    }
}
