<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowMetricsControllerTemplateMetricsTest extends WebTestCase
{
    use WorkflowProjects;

    public function test_the_picker_lists_the_template_metrics_after_the_built_in_ones_with_their_labels(): void
    {
        $client = static::createClient();
        $project = $this->projectWithTemplateMetrics('picker');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select[name="metric"] option');
        $values = $options->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertSame(['cost', 'input-tokens', 'output-tokens', 'cache-read-tokens', 'cache-write-tokens', 'duration', 'runs', 'stop-rate', 'bucket-time-each', 'bucket-time-all-each', 'bucket-calls-each', 'account-usage', 'session-usage', 'week-usage', 'design-rounds', 'merge-rate'], $values);
        $merge = $crawler->filter('select[name="metric"] option[value="merge-rate"]');
        self::assertSame('Merge <rate>', trim($merge->text()));
        self::assertSame('The share of cards that merged.', $merge->attr('title'));
        self::assertNull($crawler->filter('select[name="metric"] option[value="cost"]')->attr('title'));
    }

    public function test_a_template_metric_limits_the_controls_to_what_it_allows(): void
    {
        $client = static::createClient();
        $project = $this->projectWithTemplateMetrics('controls');
        $projectId = (string) $project->id;
        $this->em()->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=merge-rate&unit=run&statistic=p90&group=model');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertSame(['card'], self::options($form, 'unit'));
        self::assertSame(['mean', 'count'], self::options($form, 'statistic'));
        self::assertSame(['variant', 'card-type', 'none'], self::options($form, 'group'));
        self::assertSame('merge-rate', $form->filter('select[name="metric"] option[selected]')->attr('value'));
    }

    private function projectWithTemplateMetrics(string $name): Project
    {
        $project = $this->workflowProject($name);
        $project->owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($project->owner, static::getContainer());
        $binding = $this->bindLifecycle($project);
        $binding->definition = array_replace($binding->definition, ['metrics' => [
            ['key' => 'design-rounds', 'label' => 'Design rounds', 'description' => 'The design revise rounds.', 'count' => ['role' => 'design-revise']],
            ['key' => 'merge-rate', 'label' => 'Merge <rate>', 'description' => 'The share of cards that merged.', 'rate' => ['outcome' => 'merged']],
        ]]);
        $this->em()->flush();

        return $project;
    }

    /** @return list<string> */
    private static function options(Crawler $form, string $name): array
    {
        return $form->filter('select[name="'.$name.'"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
    }
}
