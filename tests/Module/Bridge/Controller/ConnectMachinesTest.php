<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConnectMachinesTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_connect_page_lists_every_bridge_of_the_owner_and_no_other(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'connect-machines@example.com');
        $project = $this->project($em, $owner, 'Connect machines');
        $otherProject = $this->project($em, $owner, 'Connect machines other');
        $live = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: 'live-version');
        $live->name = 'laptop';
        $quiet = $this->seedBridge($em, $owner, projects: [(string) $otherProject->id], cliVersion: 'quiet-version', lastSeenAt: new \DateTimeImmutable('-1 day'));
        $em->flush();
        $stranger = $this->user($em, 'connect-machines-stranger@example.com');
        $this->seedBridge($em, $stranger, projects: [(string) $project->id], cliVersion: 'foreign-version');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-connect-machine]');
        self::assertSelectorTextSame('[data-connect-machine="'.$live->id.'"] [data-bridge-label]', 'laptop');
        self::assertSelectorTextSame('[data-connect-machine="'.$live->id.'"] .lp-status-chip', 'Healthy');
        self::assertSelectorTextSame('[data-connect-machine="'.$quiet->id.'"] .lp-status-chip', 'Stale');
        self::assertStringNotContainsString('foreign-version', $crawler->text());
    }
}
