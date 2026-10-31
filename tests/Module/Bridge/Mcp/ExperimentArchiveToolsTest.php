<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Mcp;

use App\Module\Bridge\Mcp\ExperimentArchiveTool;
use App\Module\Bridge\Mcp\ExperimentGetTool;
use App\Module\Bridge\Mcp\ExperimentUnarchiveTool;
use App\Tests\Module\Bridge\ExperimentScenario;
use App\Tests\Support\McpTokenScenario;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExperimentArchiveToolsTest extends KernelTestCase
{
    use ExperimentScenario;
    use McpTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_archives_and_unarchives_an_experiment_and_experiment_get_reports_it(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-archive-tool@example.com'));
        $this->experimentRun($em, $project, $this->experimentCard($em, $project, 1), 'a', at: '-3 hours');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(['experiment' => 'model-test', 'archived' => true], $this->archiveTool()('model-test'));
        self::assertTrue($this->get('model-test')['archived']);

        self::assertSame(['experiment' => 'model-test', 'archived' => false], $this->unarchiveTool()('model-test'));
        self::assertFalse($this->get('model-test')['archived']);
    }

    public function test_it_refuses_an_experiment_that_no_run_and_no_pin_names(): void
    {
        $em = $this->em();
        $project = $this->boardProject($em, $this->user($em, 'experiment-archive-unknown@example.com'));
        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No experiment named "ghost"');

        $this->archiveTool()('ghost');
    }

    public function test_it_needs_a_bound_project(): void
    {
        $em = $this->em();
        $this->actAsUnboundMcpToken($this->user($em, 'experiment-archive-unbound@example.com'));

        $this->expectException(ToolCallException::class);

        $this->archiveTool()('model-test');
    }

    /** @return array<string, mixed> */
    private function get(string $experiment): array
    {
        $tool = self::getContainer()->get(ExperimentGetTool::class);
        self::assertInstanceOf(ExperimentGetTool::class, $tool);

        return $tool($experiment);
    }

    private function archiveTool(): ExperimentArchiveTool
    {
        $tool = self::getContainer()->get(ExperimentArchiveTool::class);
        self::assertInstanceOf(ExperimentArchiveTool::class, $tool);

        return $tool;
    }

    private function unarchiveTool(): ExperimentUnarchiveTool
    {
        $tool = self::getContainer()->get(ExperimentUnarchiveTool::class);
        self::assertInstanceOf(ExperimentUnarchiveTool::class, $tool);

        return $tool;
    }
}
