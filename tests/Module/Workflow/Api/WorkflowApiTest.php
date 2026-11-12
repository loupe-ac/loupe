<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PublicSpec;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** GET /api/v1/projects/{project}/workflow. */
final class WorkflowApiTest extends WebTestCase
{
    use WorkflowProjects;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function test_it_reads_the_template_the_columns_and_the_kinds_of_work(): void
    {
        $project = $this->workflowProject('workflow-api-read');
        $this->bindLifecycle($project);

        $this->get($project, $this->token($project->owner));

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('Workflow', $body);
        self::assertSame('lifecycle', $body['template']['key']);
        self::assertTrue($body['approvalCoversConflictResolution']);
        self::assertContains('in-progress', array_column($body['columns'], 'slug'));
        $slots = array_filter(array_column($body['columns'], 'slot'));
        self::assertContains('implementation', $slots);
        self::assertNotSame([], $body['kinds']);
        self::assertContains($body['kinds'][0]['origin'], ['template', 'app']);
        self::assertNotSame([], $body['kinds'][0]['rules']);
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_a_column_label_comes_back_translated(): void
    {
        $project = $this->workflowProject('workflow-api-label');
        $this->bindLifecycle($project);

        $this->get($project, $this->token($project->owner));

        foreach ($this->json()['columns'] as $column) {
            self::assertStringNotContainsString('board.column.', $column['label'], 'a seeded label is a translation key');
        }
    }

    public function test_a_project_with_no_workflow_answers_409_no_workflow(): void
    {
        $project = $this->workflowProject('workflow-api-none');

        $this->get($project, $this->token($project->owner));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_workflow', $this->json()['code']);
    }

    public function test_a_stranger_does_not_find_the_project_and_a_call_without_a_credential_is_refused(): void
    {
        $project = $this->workflowProject('workflow-api-stranger');
        $this->bindLifecycle($project);
        $stranger = new User(fullName: 'Stranger', email: 'workflow-api-stranger-'.uniqid().'@example.com', password: 'hashed');
        $this->em()->persist($stranger);
        $this->em()->flush();

        $this->get($project, $this->token($stranger));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('project_not_found', $this->json()['code']);

        $this->get($project, null);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_a_write_method_is_refused(): void
    {
        $project = $this->workflowProject('workflow-api-method');

        $this->client->request(Request::METHOD_POST, '/api/v1/projects/'.$project->id.'/workflow', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token($project->owner)]);

        self::assertResponseStatusCodeSame(405);
    }

    private function get(Project $project, ?string $token): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v1/projects/'.$project->id.'/workflow', server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    /** @return non-empty-string */
    private function token(User $owner): string
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
