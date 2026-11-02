<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkerRunCloseReason;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ShowInteractiveSessionCardRunApiTest extends WebTestCase
{
    use BridgeScenario;

    public function test_an_open_run_of_the_session_reads_as_open(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-open@example.com');
        $project = $this->project($this->em(), $owner, 'Card run open');
        $sessionId = Uuid::v4();
        $this->open($project, $sessionId);

        $this->get($client, $sessionId->toRfc4122(), $this->agentToken($client, $owner), $project->requireId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->reply($client)['openCardRun']);
    }

    public function test_a_session_with_no_run_or_a_closed_run_reads_as_not_open(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-none@example.com');
        $project = $this->project($this->em(), $owner, 'Card run none');
        $raw = $this->agentToken($client, $owner);
        $handle = $project->requireId()->toRfc4122();
        $other = Uuid::v4();
        $this->open($project, $other);
        $closed = Uuid::v4();
        $this->open($project, $closed);
        $runs = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        self::assertSame(1, $runs->closeSession($owner, $closed, WorkerRunCloseReason::SessionEnded));

        $this->get($client, Uuid::v4()->toRfc4122(), $raw, $handle);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->reply($client)['openCardRun']);

        $this->get($client, $closed->toRfc4122(), $raw, $handle);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->reply($client)['openCardRun']);
    }

    public function test_a_run_in_another_project_of_the_owner_does_not_count(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-sibling@example.com');
        $asked = $this->project($this->em(), $owner, 'Card run asked');
        $sibling = $this->project($this->em(), $owner, 'Card run sibling');
        $sessionId = Uuid::v4();
        $this->open($sibling, $sessionId);

        $this->get($client, $sessionId->toRfc4122(), $this->agentToken($client, $owner), $asked->requireId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->reply($client)['openCardRun']);
    }

    public function test_the_session_of_another_account_reads_as_not_open(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-mine@example.com');
        $stranger = $this->user($this->em(), 'card-run-stranger@example.com');
        $project = $this->project($this->em(), $owner, 'Card run mine');
        $foreign = $this->project($this->em(), $stranger, 'Card run foreign');
        $sessionId = Uuid::v4();
        $this->open($foreign, $sessionId);

        $this->get($client, $sessionId->toRfc4122(), $this->agentToken($client, $owner), $project->requireId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->reply($client)['openCardRun']);
    }

    public function test_the_answer_names_the_workflow_template_of_the_project(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-template@example.com');
        $bound = $this->project($this->em(), $owner, 'Card run bound');
        $unbound = $this->project($this->em(), $owner, 'Card run unbound');
        $bind = static::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $bind);
        $bind(new BindWorkflowTemplateCommand($bound, 'simple', []));
        $raw = $this->agentToken($client, $owner);

        $this->get($client, Uuid::v4()->toRfc4122(), $raw, $bound->requireId()->toRfc4122());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('simple', $this->reply($client)['template']);

        $this->get($client, Uuid::v4()->toRfc4122(), $raw, $unbound->requireId()->toRfc4122());
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->reply($client)['template']);
    }

    public function test_the_header_may_carry_the_slug_of_the_project(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-slug@example.com');
        $project = $this->project($this->em(), $owner, 'Card run slug');
        $project->slug = 'card-run-slug';
        $this->em()->flush();
        $sessionId = Uuid::v4();
        $this->open($project, $sessionId);

        $this->get($client, $sessionId->toRfc4122(), $this->agentToken($client, $owner), 'card-run-slug');

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->reply($client)['openCardRun']);
    }

    public function test_a_project_of_another_account_or_a_missing_one_gets_the_same_refusal(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-refuse@example.com');
        $stranger = $this->user($this->em(), 'card-run-refuse-other@example.com');
        $foreign = $this->project($this->em(), $stranger, 'Card run refuse foreign');
        $raw = $this->agentToken($client, $owner);
        $sessionId = Uuid::v4()->toRfc4122();

        $this->get($client, $sessionId, $raw, $foreign->requireId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        $foreignBody = (string) $client->getResponse()->getContent();
        self::assertSame(['error' => 'unknown_project'], json_decode($foreignBody, true));

        $this->get($client, $sessionId, $raw, Uuid::v4()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        self::assertSame($foreignBody, (string) $client->getResponse()->getContent());
    }

    public function test_a_missing_or_blank_project_header_is_refused_even_for_an_owner_of_one_project(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $owner = $this->user($this->em(), 'card-run-header@example.com');
        $this->project($this->em(), $owner, 'Card run header');
        $raw = $this->agentToken($client, $owner);
        $sessionId = Uuid::v4()->toRfc4122();

        $this->get($client, $sessionId, $raw, null);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'project_header_required'], $this->reply($client));

        $this->get($client, $sessionId, $raw, '  ');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'project_header_required'], $this->reply($client));
    }

    public function test_a_session_id_that_is_not_a_uuid_is_refused(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'card-run-session@example.com');
        $project = $this->project($this->em(), $owner, 'Card run session');

        $this->get($client, 'not-a-uuid', $this->agentToken($client, $owner), $project->requireId()->toRfc4122());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'invalid_session_id'], $this->reply($client));
    }

    public function test_the_endpoint_needs_an_agent_token(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'card-run-auth@example.com');
        $project = $this->project($this->em(), $owner, 'Card run auth');
        $handle = $project->requireId()->toRfc4122();

        $this->get($client, Uuid::v4()->toRfc4122(), null, $handle);
        self::assertResponseStatusCodeSame(401);

        $this->get($client, Uuid::v4()->toRfc4122(), AgentCredential::tokenFor(static::getContainer(), $owner, 'mcp', $project), $handle);
        self::assertResponseStatusCodeSame(403);
    }

    private function open(Project $project, Uuid $sessionId): void
    {
        $runs = static::getContainer()->get(InteractiveRuns::class);
        self::assertInstanceOf(InteractiveRuns::class, $runs);
        $runs->open($project, Uuid::v7(), 1, $sessionId, 'design', null);
    }

    private function get(KernelBrowser $client, string $sessionId, ?string $raw, ?string $handle): void
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $raw) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$raw;
        }
        if (null !== $handle) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', AuthenticatedProjectResolver::PROJECT_HEADER))] = $handle;
        }

        $client->request(Request::METHOD_GET, '/api/interactive-sessions/'.$sessionId.'/card-run', server: $server);
    }

    /** @return array<string, mixed> */
    private function reply(KernelBrowser $client): array
    {
        $reply = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($reply);

        return $reply;
    }
}
