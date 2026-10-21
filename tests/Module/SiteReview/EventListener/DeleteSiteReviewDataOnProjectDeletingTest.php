<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewScreenshot;
use App\Module\SiteReview\EventListener\DeleteSiteReviewDataOnProjectDeleting;
use App\Tests\Support\TinyImage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteSiteReviewDataOnProjectDeletingTest extends KernelTestCase
{
    private const array TABLES = ['site_review_comments', 'site_review_comment_anchors', 'site_review_screenshots'];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** The listener runs alone here, so the cascade of the comment row cannot hide a table it forgot. */
    public function test_the_listener_deletes_the_screenshots_of_its_project_only(): void
    {
        $doomed = $this->seed('site-review-listener-doomed');
        $spared = $this->seed('site-review-listener-spared');

        $listener = self::getContainer()->get(DeleteSiteReviewDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteSiteReviewDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(array_fill_keys(self::TABLES, 0), $this->counts($doomed));
        self::assertSame(array_fill_keys(self::TABLES, 1), $this->counts($spared));
    }

    public function test_deleting_a_project_with_a_screenshot_succeeds(): void
    {
        $doomed = $this->seed('site-review-project-delete');
        $doomedId = (string) $doomed->id;

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        $connection = $this->em()->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM projects WHERE id = ?', [$doomedId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM site_review_screenshots WHERE project_id = ?', [$doomedId]));
    }

    private function seed(string $name): Project
    {
        $em = $this->em();
        $user = new User(fullName: 'U', email: $name.'@example.com', password: 'x');
        $em->persist($user);
        $project = new Project($user, $name);
        $em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'A note', 'https://app/x');
        $comment->addAnchor('.a', 'A');
        $em->persist($comment);
        $png = TinyImage::png();
        $em->persist(new SiteReviewScreenshot($comment, $project, 'image/png', $png, \strlen($png), 1, 1));
        $em->flush();

        return $project;
    }

    /** @return array<string, int> */
    private function counts(Project $project): array
    {
        $connection = $this->em()->getConnection();
        $counts = [];
        foreach (self::TABLES as $table) {
            $column = 'site_review_comment_anchors' === $table
                ? 'comment_id IN (SELECT id FROM site_review_comments WHERE project_id = ?)'
                : 'project_id = ?';
            $counts[$table] = (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $column), [(string) $project->id]);
        }

        return $counts;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
