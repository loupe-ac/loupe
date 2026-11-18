<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Command;

use App\Module\Artifact\Command\ListArtifactsCommand;
use App\Module\Artifact\Command\ListArtifactsHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;

/** The artifacts page reads its rows in four statements, however many artifacts the project holds. */
final class ListArtifactsQueryCountTest extends KernelTestCase
{
    use ArtifactScenario;

    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        self::bootKernel();

        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;

        $flags = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(FeatureFlagReaderInterface::class, $flags);
        $flags->all();
    }

    public function test_fifty_artifacts_cost_the_same_statements_as_five(): void
    {
        $small = $this->projectWith('artifacts-q-small@example.com', 5);
        $big = $this->projectWith('artifacts-q-big@example.com', 50);
        $this->em()->clear();

        [$forSmall, $smallItems] = $this->listing($small);
        [$forBig, $bigItems] = $this->listing($big);

        // Guards: both lists hold their artifacts, so a page that read nothing cannot pass.
        self::assertSame(5, $smallItems);
        self::assertSame(50, $bigItems);
        self::assertNotEmpty(array_filter($forBig, static fn (string $sql): bool => str_contains($sql, 'artifact_versions')));
        self::assertCount(4, $forSmall, implode("\n", $forSmall));
        self::assertCount(\count($forSmall), $forBig, "The artifacts page query count grew with the artifacts:\n".implode("\n", $forBig));
    }

    private function projectWith(string $email, int $artifacts): Project
    {
        /** @var non-empty-string $email */
        $project = $this->switchedOnProject($email, $email);
        for ($i = 0; $i < $artifacts; ++$i) {
            $this->storedArtifact($project, 'artifact-'.$i, [['index.html' => '<p>'.$i.'</p>'], ['index.html' => '<p>'.$i.' again</p>']]);
        }

        return $project;
    }

    /** @return array{list<string>, int} the statements, and the number of listed artifacts */
    private function listing(Project $stored): array
    {
        $project = $this->em()->find(Project::class, $stored->id) ?? throw new \LogicException('The project is stored.');
        $handler = self::getContainer()->get(ListArtifactsHandler::class);
        self::assertInstanceOf(ListArtifactsHandler::class, $handler);

        $this->queries->reset();
        $view = $handler(new ListArtifactsCommand($project));

        $statements = [];
        foreach ($this->queries->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $statements[] = (string) $query['sql'];
            }
        }

        return [$statements, \count($view->items)];
    }
}
