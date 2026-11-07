<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Repository;

use App\Module\Insights\Entity\PublicLink;
use App\Module\Insights\Entity\PublicSeriesLabel;
use App\Module\Insights\Entity\PublicSeriesLabelKind;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Repository\PublicSeriesLabelRepository;
use App\Tests\Module\Insights\InsightsScenario;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PublicLinkRepositoryTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_link_is_found_by_its_code_its_graph_and_its_experiment(): void
    {
        $project = $this->scenarioProject('links-find');
        $graph = $this->seedSavedGraph($project);
        $graphLink = PublicLink::forGraph($graph);
        $experimentLink = PublicLink::forExperiment($project, 'implement');
        $this->em()->persist($graphLink);
        $this->em()->persist($experimentLink);
        $this->em()->flush();
        $this->em()->clear();

        self::assertSame($graphLink->id?->toRfc4122(), $this->links()->findByCode($graphLink->code)?->id?->toRfc4122());
        self::assertSame($graphLink->code, $this->links()->findForGraph($graph)?->code);
        self::assertSame($experimentLink->code, $this->links()->findForExperiment($project, 'implement')?->code);
        self::assertNull($this->links()->findByCode('aaaaaaaaaaaa'));
    }

    public function test_the_experiment_links_of_a_project_come_by_experiment_name_and_leave_out_graphs_and_other_projects(): void
    {
        $project = $this->scenarioProject('links-by-experiment');
        $this->em()->persist(PublicLink::forGraph($this->seedSavedGraph($project)));
        $trial = PublicLink::forExperiment($project, 'trial');
        $this->em()->persist($trial);
        $this->em()->persist(PublicLink::forExperiment($this->scenarioProject('links-other'), 'trial'));
        $this->em()->flush();
        $this->em()->clear();
        $stored = $this->em()->find($project::class, $project->id) ?? throw new \LogicException();

        $links = $this->links()->findExperimentLinks($stored);

        self::assertSame(['trial'], array_keys($links));
        self::assertSame($trial->code, $links['trial']->code);
    }

    public function test_the_database_refuses_a_second_link_for_one_graph(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('links-graph-unique'));
        $this->em()->persist(PublicLink::forGraph($graph));
        $this->em()->flush();
        $this->em()->persist(PublicLink::forGraph($graph));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function test_the_database_refuses_a_second_link_for_one_experiment(): void
    {
        $project = $this->scenarioProject('links-experiment-unique');
        $this->em()->persist(PublicLink::forExperiment($project, 'implement'));
        $this->em()->flush();
        $this->em()->persist(PublicLink::forExperiment($project, 'implement'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function test_the_database_refuses_a_duplicate_code(): void
    {
        $project = $this->scenarioProject('links-code-unique');
        $first = PublicLink::forExperiment($project, 'one');
        $this->em()->persist($first);
        $this->em()->flush();
        $this->em()->persist(new PublicLink($project, $first->kind, $first->code, experiment: 'two'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    public function test_the_database_check_refuses_a_row_with_two_targets(): void
    {
        $project = $this->scenarioProject('links-check');
        $graph = $this->seedSavedGraph($project);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('chk_insights_public_link_target');
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO insights_public_links (id, project_id, kind, saved_graph_id, experiment, code, enabled, created_at) VALUES (:id, :project, :kind, :graph, :experiment, :code, true, now())',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'project' => $project->id?->toRfc4122(),
                'kind' => 'graph',
                'graph' => $graph->id?->toRfc4122(),
                'experiment' => 'implement',
                'code' => 'zzzzzzzzzzzz',
            ],
        );
    }

    public function test_removing_the_graph_row_deletes_its_link(): void
    {
        $graph = $this->seedSavedGraph($this->scenarioProject('links-cascade'));
        $link = PublicLink::forGraph($graph);
        $this->em()->persist($link);
        $this->em()->flush();
        $code = $link->code;

        $this->em()->createQuery('DELETE '.$graph::class.' g WHERE g.id = :id')->setParameter('id', $graph->id, 'uuid')->execute();
        $this->em()->clear();

        self::assertNull($this->links()->findByCode($code));
    }

    public function test_series_numbers_are_unique_per_project_kind_and_key(): void
    {
        $project = $this->scenarioProject('labels');
        $this->em()->persist(new PublicSeriesLabel($project, PublicSeriesLabelKind::Account, 'acme', 1));
        $this->em()->persist(new PublicSeriesLabel($project, PublicSeriesLabelKind::Bridge, 'acme', 1));
        $this->em()->flush();

        self::assertSame(['acme' => 1], $this->labels()->numbersFor($project, PublicSeriesLabelKind::Account));

        $this->em()->persist(new PublicSeriesLabel($project, PublicSeriesLabelKind::Account, 'acme', 2));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }

    private function links(): PublicLinkRepository
    {
        return static::getContainer()->get(PublicLinkRepository::class);
    }

    private function labels(): PublicSeriesLabelRepository
    {
        return static::getContainer()->get(PublicSeriesLabelRepository::class);
    }
}
