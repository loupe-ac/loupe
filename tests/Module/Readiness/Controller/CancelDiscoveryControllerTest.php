<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Controller;

use App\Module\Project\Entity\Project;
use App\Module\Readiness\Controller\CancelDiscoveryController;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Service\ReadinessChecklist;
use App\Tests\Module\Readiness\ReadinessScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

final class CancelDiscoveryControllerTest extends WebTestCase
{
    use ReadinessScenario;

    public function test_the_owner_cancels_discovery_and_returns_to_the_workshop(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-cancel@example.com');
        $project = $this->project($owner, 'Cancel discovery');
        $run = $this->requestedRun($project);

        $client->loginUser($owner);
        $this->post($client, $this->cancelUrl($project));

        self::assertResponseRedirects('/projects/'.$project->id);
        $stored = $this->stored($run);
        self::assertSame([DiscoveryRunState::Failed, (string) $owner->id], [$stored->state, (string) $stored->cancelledBy?->id]);
        $client->followRedirect();
        self::assertSelectorExists('[data-readiness-row="repository"][data-readiness-discovery-state="failed"]');
    }

    public function test_the_action_checks_the_token_of_the_cancel_form(): void
    {
        $attributes = new \ReflectionClass(CancelDiscoveryController::class)->getAttributes(CsrfToken::class);

        self::assertCount(1, $attributes);
        self::assertSame([ReadinessChecklist::CANCEL_TOKEN], $attributes[0]->getArguments());
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-cancel-csrf@example.com');
        $project = $this->project($owner, 'Cancel discovery csrf');
        $run = $this->requestedRun($project);

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->cancelUrl($project), ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DiscoveryRunState::Requested, $this->stored($run)->state);
    }

    public function test_a_user_who_does_not_own_the_project_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-cancel-owner@example.com');
        $stranger = $this->user('discovery-cancel-stranger@example.com');
        $project = $this->project($owner, 'Cancel discovery private');
        $run = $this->requestedRun($project);

        $client->loginUser($stranger);
        $this->post($client, $this->cancelUrl($project));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(DiscoveryRunState::Requested, $this->stored($run)->state);
    }

    public function test_the_route_takes_post_only(): void
    {
        $client = static::createClient();
        $owner = $this->user('discovery-cancel-get@example.com');
        $project = $this->project($owner, 'Cancel discovery get');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->cancelUrl($project));

        self::assertResponseStatusCodeSame(405);
    }

    private function requestedRun(Project $project): DiscoveryRun
    {
        $run = new DiscoveryRun($project);
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function stored(DiscoveryRun $run): DiscoveryRun
    {
        $this->em()->clear();

        return $this->em()->find(DiscoveryRun::class, $run->id) ?? throw new \LogicException('The run is stored.');
    }

    private function cancelUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/discovery/cancel';
    }

    /** The same-origin sentinel passes the CSRF check, so a refusal is the voter's. */
    private function post(KernelBrowser $client, string $url): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }
}
