<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Api;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\ApprovalRules;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PublicSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** GET and PATCH /api/v1/projects/{project}/workflow/automation. */
final class BoardAutomationApiTest extends WebTestCase
{
    use WorkflowProjects;

    private KernelBrowser $client;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->project = $this->workflowProject('automation-api');
        $this->bindLifecycle($this->project);
        $this->token = $this->tokenFor($this->project->owner);
    }

    public function test_it_reads_the_defaults_of_a_project_that_saved_nothing(): void
    {
        $this->send(Request::METHOD_GET);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('BoardAutomation', $body);
        self::assertSame(['enabled', 'stuckDelayMinutes', 'approvalCoversConflictResolution'], array_keys($body));
        self::assertSame(BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES, $body['stuckDelayMinutes']);
        self::assertTrue($body['approvalCoversConflictResolution']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_it_changes_all_three_settings_and_answers_their_values(): void
    {
        $this->send(Request::METHOD_PATCH, '{"enabled":false,"stuckDelayMinutes":40,"approvalCoversConflictResolution":false}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['enabled' => false, 'stuckDelayMinutes' => 40, 'approvalCoversConflictResolution' => false], $this->json());
        $stored = $this->stored();
        self::assertFalse($stored->enabled);
        self::assertSame(40, $stored->stuckDelayMinutes);
        self::assertFalse(static::getContainer()->get(ApprovalRules::class)->coversConflictResolution($this->project->requireId()));

        $this->send(Request::METHOD_GET);
        self::assertSame(['enabled' => false, 'stuckDelayMinutes' => 40, 'approvalCoversConflictResolution' => false], $this->json());
    }

    public function test_a_setting_left_out_keeps_its_value(): void
    {
        $this->send(Request::METHOD_PATCH, '{"stuckDelayMinutes":40,"approvalCoversConflictResolution":false}');
        $this->send(Request::METHOD_PATCH, '{"enabled":false}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['enabled' => false, 'stuckDelayMinutes' => 40, 'approvalCoversConflictResolution' => false], $this->json());
    }

    public function test_an_empty_body_changes_nothing(): void
    {
        $this->send(Request::METHOD_PATCH, '{}');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(BoardAutomationSettings::DEFAULT_STUCK_DELAY_MINUTES, $this->json()['stuckDelayMinutes']);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedBodies(): iterable
    {
        yield 'delay zero' => ['{"stuckDelayMinutes":0}'];
        yield 'delay over a day' => ['{"stuckDelayMinutes":1441}'];
        yield 'delay as text' => ['{"stuckDelayMinutes":"soon"}'];
        yield 'enabled as text' => ['{"enabled":"yes"}'];
        yield 'enabled null' => ['{"enabled":null}'];
    }

    #[DataProvider('refusedBodies')]
    public function test_a_bad_value_is_refused_and_nothing_changes(string $body): void
    {
        $this->send(Request::METHOD_PATCH, '{"approvalCoversConflictResolution":false,'.ltrim($body, '{'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertTrue(static::getContainer()->get(ApprovalRules::class)->coversConflictResolution($this->project->requireId()), 'a refused body changes none of the settings');
    }

    public function test_the_approval_rule_of_a_project_with_no_workflow_answers_409_and_leaves_the_other_settings(): void
    {
        $project = $this->workflowProject('automation-api-unbound');
        $token = $this->tokenFor($project->owner);

        $this->send(Request::METHOD_PATCH, '{"enabled":false,"approvalCoversConflictResolution":false}', $project, $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_workflow', $this->json()['code']);
        $this->send(Request::METHOD_GET, '', $project, $token);
        self::assertTrue($this->json()['enabled']);
    }

    public function test_a_project_with_no_workflow_still_takes_the_other_two_settings(): void
    {
        $project = $this->workflowProject('automation-api-unbound-ok');

        $this->send(Request::METHOD_PATCH, '{"enabled":false,"stuckDelayMinutes":10}', $project, $this->tokenFor($project->owner));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['enabled' => false, 'stuckDelayMinutes' => 10, 'approvalCoversConflictResolution' => true], $this->json());
    }

    public function test_a_stranger_does_not_find_the_project_and_no_credential_is_refused(): void
    {
        $stranger = new User(fullName: 'Stranger', email: 'automation-api-stranger-'.uniqid().'@example.com', password: 'hashed');
        $this->em()->persist($stranger);
        $this->em()->flush();
        $strangerToken = $this->tokenFor($stranger);

        foreach ([[Request::METHOD_GET, ''], [Request::METHOD_PATCH, '{"enabled":false}']] as [$method, $body]) {
            $this->send($method, $body, token: $strangerToken);
            self::assertResponseStatusCodeSame(404, $method);
            self::assertSame('project_not_found', $this->json()['code']);

            $this->send($method, $body, token: null);
            self::assertResponseStatusCodeSame(401, $method);
        }
        self::assertTrue($this->stored()->enabled);
    }

    public function test_a_body_that_is_not_json_is_refused(): void
    {
        $this->send(Request::METHOD_PATCH, '{nope');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_json', $this->json()['code']);
    }

    private function stored(): BoardAutomationSettings
    {
        $this->em()->clear();
        $repository = static::getContainer()->get(BoardAutomationSettingsRepository::class);
        self::assertInstanceOf(BoardAutomationSettingsRepository::class, $repository);
        $project = $this->em()->find(Project::class, $this->project->id) ?? throw new \LogicException('The project is gone.');

        return $repository->findOneByProject($project) ?? new BoardAutomationSettings($project);
    }

    private function send(string $method, string $body = '', ?Project $project = null, ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, '/api/v1/projects/'.($project ?? $this->project)->id.'/workflow/automation', server: ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
    }

    /** @return non-empty-string */
    private function tokenFor(User $owner): string
    {
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $this->em()->flush();

        return ApiKeyCredential::mint(static::getContainer(), $owner);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
