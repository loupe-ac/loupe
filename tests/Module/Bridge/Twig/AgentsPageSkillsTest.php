<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Twig;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AgentsPageSkillsTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_agents_page_shows_the_version_and_state_of_each_skill_and_the_reason_of_a_failed_one(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-skills-rows@example.com');
        $project = $this->project($em, $owner, 'Skilled');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->skills = [
            ['package' => 'loupe-lifecycle', 'version' => '1.2.0', 'state' => 'current', 'reason' => null],
            ['package' => 'acme-extras', 'version' => '0.3.1', 'state' => 'failed', 'reason' => 'download failed'],
        ];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-agent-skill]'));
        $current = $crawler->filter('[data-agent-skill="loupe-lifecycle"]');
        self::assertSame('current', $current->attr('data-agent-skill-state'));
        self::assertSame('1.2.0', $current->filter('[data-agent-skill-version]')->text());
        self::assertCount(0, $current->filter('[data-agent-skill-reason]'));
        $failed = $crawler->filter('[data-agent-skill="acme-extras"]');
        self::assertSame('failed', $failed->attr('data-agent-skill-state'));
        self::assertSame('0.3.1', $failed->filter('[data-agent-skill-version]')->text());
        self::assertSame('download failed', $failed->filter('[data-agent-skill-reason]')->text());
        self::assertSelectorTextContains('[data-agent-skills] [data-agent-section-summary]', '1 of 2 failed');
    }

    public function test_a_bridge_with_no_skill_report_shows_no_skills_section(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-skills-none@example.com');
        $project = $this->project($em, $owner, 'Unskilled');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->skills = [];
        $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-agent-skills]');
    }
}
