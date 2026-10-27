<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Repository;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ForgePullRequestRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ForgePullRequestRepository $pullRequests;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $pullRequests = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $pullRequests);
        $this->pullRequests = $pullRequests;
    }

    public function test_find_by_keys_answers_the_rows_of_the_keys_in_any_case(): void
    {
        $project = $this->project();
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $seven = $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/loupe', 7);
        $eight = $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/loupe', 8);
        $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/other', 7);
        $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/loupe', 9);
        $this->pullRequests->insertIfMissing($this->projectId(), 'github', 'loupe-ac/loupe', 7);

        $rows = $this->pullRequests->findByKeys($projectId, [
            ['forge' => 'github', 'repository' => 'Loupe-AC/Loupe', 'number' => 7],
            ['forge' => 'github', 'repository' => 'loupe-ac/loupe', 'number' => 8],
            ['forge' => 'gitlab', 'repository' => 'loupe-ac/loupe', 'number' => 9],
            ['forge' => 'github', 'repository' => 'loupe-ac/missing', 'number' => 7],
        ]);

        $ids = array_map(static fn (ForgePullRequest $row): string => (string) $row->id, $rows);
        sort($ids);
        $expected = [(string) $seven, (string) $eight];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function test_find_by_keys_answers_nothing_for_no_keys(): void
    {
        $projectId = $this->projectId();
        $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/loupe', 7);

        self::assertSame([], $this->pullRequests->findByKeys($projectId, []));
    }

    public function test_delete_by_key_deletes_the_row_of_the_key_in_any_case_and_no_other(): void
    {
        $projectId = $this->projectId();
        $otherProjectId = $this->projectId();
        $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/loupe', 7);
        $eight = $this->pullRequests->insertIfMissing($projectId, 'github', 'loupe-ac/loupe', 8);
        $elsewhere = $this->pullRequests->insertIfMissing($otherProjectId, 'github', 'loupe-ac/loupe', 7);

        $this->pullRequests->deleteByKey($projectId, 'github', 'Loupe-AC/Loupe', 7);

        $remaining = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM forge_pull_requests WHERE project_id IN (:projects) ORDER BY number, project_id',
            ['projects' => [$projectId->toRfc4122(), $otherProjectId->toRfc4122()]],
            ['projects' => ArrayParameterType::STRING],
        );
        $expected = [(string) $elsewhere, (string) $eight];
        sort($expected);
        sort($remaining);
        self::assertSame($expected, $remaining);
    }

    public function test_find_by_head_branch_answers_the_rows_of_one_repository_whose_head_is_the_branch(): void
    {
        $project = $this->project();
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $match = $this->row($project, 'loupe-ac/loupe', 1, 'epic/42');
        $merged = $this->row($project, 'loupe-ac/loupe', 2, 'epic/42');
        $merged->state = PullRequestState::Merged;
        $this->row($project, 'loupe-ac/loupe', 3, 'epic/43');
        $this->row($project, 'loupe-ac/other', 4, 'epic/42');
        $this->row($this->project(), 'loupe-ac/loupe', 5, 'epic/42');
        $this->em->flush();

        $rows = $this->pullRequests->findByHeadBranch($projectId, 'github', 'Loupe-AC/Loupe', 'epic/42');

        self::assertSame([$match->number, $merged->number], array_map(static fn (ForgePullRequest $row): int => $row->number, $rows));
        self::assertSame([], $this->pullRequests->findByHeadBranch($projectId, 'gitlab', 'loupe-ac/loupe', 'epic/42'));
    }

    private function row(Project $project, string $repository, int $number, string $headBranch): ForgePullRequest
    {
        $row = new ForgePullRequest($project, 'github', $repository, $number);
        $row->headBranch = $headBranch;
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    public function test_ruleset_mismatches_flag_only_open_rows_of_the_project_and_repositories_asked(): void
    {
        $projectId = $this->projectId();
        $otherProjectId = $this->projectId();
        $this->flagged($projectId, 'loupe-ac/review', 1, requiresReview: true);
        $this->flagged($projectId, 'Loupe-AC/Unpinned', 2, unpinned: true);
        $this->flagged($projectId, 'loupe-ac/both', 3, requiresReview: true, unpinned: true);
        $this->flagged($projectId, 'loupe-ac/closed', 4, requiresReview: true, state: PullRequestState::Closed);
        $this->flagged($projectId, 'loupe-ac/clean', 5);
        $this->flagged($projectId, 'loupe-ac/unasked', 6, requiresReview: true);
        $this->flagged($otherProjectId, 'loupe-ac/clean', 7, requiresReview: true, unpinned: true);

        $found = $this->pullRequests->findRulesetMismatches($projectId, [
            ['forge' => 'github', 'path' => 'loupe-ac/review'],
            ['forge' => 'github', 'path' => 'loupe-ac/unpinned'],
            ['forge' => 'github', 'path' => 'Loupe-AC/Both'],
            ['forge' => 'github', 'path' => 'loupe-ac/closed'],
            ['forge' => 'github', 'path' => 'loupe-ac/clean'],
        ]);

        self::assertEquals([
            ForgePullRequestRepository::repositoryKey('github', 'loupe-ac/review') => ['requiresReview' => true, 'unpinned' => false],
            ForgePullRequestRepository::repositoryKey('github', 'loupe-ac/unpinned') => ['requiresReview' => false, 'unpinned' => true],
            ForgePullRequestRepository::repositoryKey('github', 'loupe-ac/both') => ['requiresReview' => true, 'unpinned' => true],
        ], $found);
    }

    public function test_ruleset_mismatches_merge_the_flags_of_several_open_rows_of_one_repository(): void
    {
        $projectId = $this->projectId();
        $this->flagged($projectId, 'loupe-ac/loupe', 1, requiresReview: true);
        $this->flagged($projectId, 'loupe-ac/loupe', 2, unpinned: true);

        $found = $this->pullRequests->findRulesetMismatches($projectId, [['forge' => 'github', 'path' => 'loupe-ac/loupe']]);

        self::assertSame(
            [ForgePullRequestRepository::repositoryKey('github', 'loupe-ac/loupe') => ['requiresReview' => true, 'unpinned' => true]],
            $found,
        );
    }

    public function test_ruleset_mismatches_ignore_a_row_whose_base_is_not_the_default_branch(): void
    {
        $projectId = $this->projectId();
        $this->flagged($projectId, 'loupe-ac/loupe', 1, unpinned: true, base: 'epic/12');

        self::assertSame([], $this->pullRequests->findRulesetMismatches($projectId, [['forge' => 'github', 'path' => 'loupe-ac/loupe']]));
    }

    public function test_ruleset_mismatches_answer_nothing_for_no_repositories(): void
    {
        $projectId = $this->projectId();
        $this->flagged($projectId, 'loupe-ac/loupe', 1, requiresReview: true);

        self::assertSame([], $this->pullRequests->findRulesetMismatches($projectId, []));
    }

    private function flagged(Uuid $projectId, string $repository, int $number, bool $requiresReview = false, bool $unpinned = false, PullRequestState $state = PullRequestState::Open, string $base = 'main'): void
    {
        $id = $this->pullRequests->insertIfMissing($projectId, 'github', $repository, $number);
        $row = $this->em->find(ForgePullRequest::class, $id) ?? throw new \LogicException('The row exists.');
        $row->baseRequiresReview = $requiresReview;
        $row->approvalCheckUnpinned = $unpinned;
        $row->state = $state;
        $row->baseBranch = $base;
        $row->defaultBranch = 'main';
        $this->em->flush();
    }

    private function projectId(): Uuid
    {
        return $this->project()->id ?? throw new \LogicException('The project is persisted.');
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Riley', email: 'forge-rows-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'forge-rows-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
