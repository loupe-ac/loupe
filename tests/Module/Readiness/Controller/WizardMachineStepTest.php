<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Module\Bridge\Entity\Bridge;
use App\Tests\Module\Readiness\ReadinessScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class WizardMachineStepTest extends WebTestCase
{
    use ReadinessScenario;

    public function test_a_ready_bridge_marks_its_rows_done_and_enables_continue(): void
    {
        $client = static::createClient();
        $owner = $this->user('wizard-machine-ready@example.com');
        $project = $this->project($owner, 'Wizard machine ready');
        $bridge = new Bridge(AgentCredential::managed($this->em(), $owner, $owner->id), Uuid::v4(), [(string) $project->id], '1.2.3', new \DateTimeImmutable());
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_APP_PROMPTS];
        $this->em()->persist($bridge);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter('[data-readiness-row="bridge"]')->attr('data-readiness-done'));
        self::assertSame('1', $crawler->filter('[data-readiness-row="cli"]')->attr('data-readiness-done'));
        self::assertSame('Done', trim($crawler->filter('[data-readiness-row="bridge"] .lp-status-chip')->text()));
        self::assertNull($crawler->filter('[data-wizard-continue]')->attr('disabled'));
    }
}
