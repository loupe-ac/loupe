<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Twig;

use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

/** The Agents page builds its agents list once, and its hooks section reuses the bridges of that list. */
final class AgentsPageQueryCountTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_page_reads_the_try_now_overrides_twice_for_each_bridge(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-page-q@example.com');
        $project = $this->project($em, $owner, 'Counted');
        foreach (range(1, 3) as $ignored) {
            $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        }
        $em->clear();

        $client->loginUser($owner);
        $client->enableProfiler();
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        // Guards: the page lists the three bridges and their hooks, so a page that read nothing cannot pass.
        self::assertCount(3, $crawler->filter('[data-bridge-hooks]'));
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $reads = [];
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                if (str_contains((string) $query['sql'], 'FROM bridge_account_try_now')) {
                    $reads[] = (string) $query['sql'];
                }
            }
        }

        self::assertCount(6, $reads, implode("\n", $reads));
    }
}
