<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class AnalyticsTopicExtensionTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_the_twig_function_returns_the_analytics_topic_of_the_project(): void
    {
        self::bootKernel();
        $project = $this->scenarioProject('analytics-twig');
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $topics = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $rendered = $twig->createTemplate('{{ analytics_topic(project) }}')->render(['project' => $project]);

        self::assertSame($topics->forAnalytics($project->requireId()), $rendered);
    }
}
