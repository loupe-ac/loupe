<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Workflow;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BlockingRepository;
use App\Module\Workflow\Contract\WorkHolds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ForgeRepositoryWorkHoldsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private WorkHolds $holds;

    private int $sequence = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $holds = self::getContainer()->get(WorkHolds::class);
        self::assertInstanceOf(WorkHolds::class, $holds);
        $this->holds = $holds;
    }

    public function test_a_project_with_no_repository_has_no_hold(): void
    {
        self::assertNull($this->holds->heldProject($this->project()->id ?? throw new \LogicException('Persisted.')));
    }

    public function test_a_project_whose_repositories_fit_has_no_hold(): void
    {
        $project = $this->project();
        $this->repository($project, 'blocking', []);
        $this->repository($project, 'warning', ['deletion_allowed']);

        self::assertNull($this->holds->heldProject($project->id ?? throw new \LogicException('Persisted.')));
    }

    public function test_a_project_with_a_blocking_gap_is_held_and_names_the_repository_and_the_count(): void
    {
        $project = $this->project();
        $this->repository($project, 'blocking', []);
        $blocked = $this->repository($project, 'blocking', ['no_pull_request_rule', 'check_missing']);

        $hold = $this->holds->heldProject($project->id ?? throw new \LogicException('Persisted.'));

        self::assertNotNull($hold);
        self::assertEquals([new BlockingRepository((string) $blocked->id, $blocked->path, 2)], $hold->repositories);
        self::assertSame(2, $hold->blockingGaps);
    }

    public function test_the_hold_of_a_project_ignores_the_blocking_gaps_of_another_project(): void
    {
        $other = $this->project();
        $this->repository($other, 'blocking', ['no_pull_request_rule']);

        self::assertNull($this->holds->heldProject($this->project()->id ?? throw new \LogicException('Persisted.')));
    }

    public function test_an_unknown_project_has_no_hold(): void
    {
        self::assertNull($this->holds->heldProject(\Symfony\Component\Uid\Uuid::v7()));
    }

    /** @param list<string> $gaps */
    private function repository(Project $project, string $mark, array $gaps): ForgeRepository
    {
        $repository = new ForgeRepository($project, 'github', 'ext-'.++$this->sequence, 'acme/repo-'.$this->sequence, ForgeRepositorySource::Hook);
        $this->em->persist($repository);
        $check = new ForgeRepositoryCheck($repository, $project);
        $check->items = [['key' => 'approval-gate', 'mark' => $mark, 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []]];
        $this->em->persist($check);
        $this->em->flush();

        return $repository;
    }

    private function project(): Project
    {
        $owner = new User(fullName: 'Owner', email: 'holds-'.++$this->sequence.'-'.uniqid().'@example.com', password: 'x');
        $project = new Project($owner, 'holds-'.uniqid());
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
