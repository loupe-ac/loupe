<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Api;

use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\ApiKeyCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The visibility of a card value on the public API: every row names it, and a key with internal visibility refuses a write. */
final class CardInternalValuesApiTest extends WebTestCase
{
    use WorkflowProjects;

    private const string KEY = 'tech-design-interactive';
    private const string INTERNAL_KEY = 'session-start-approved';

    private KernelBrowser $client;
    private Project $project;
    private string $token;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->project = $this->workflowProject('internal-values-api');
        $this->bindLifecycleWithInternalValue($this->project, self::INTERNAL_KEY);
        $this->project->owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($this->project->owner, static::getContainer());
        $this->em()->flush();
        $this->token = ApiKeyCredential::mint(static::getContainer(), $this->project->owner);
    }

    public function test_each_value_row_names_its_visibility(): void
    {
        $this->send(Request::METHOD_POST, '/cards', '{"title":"A card","body":"","type":"feature"}');
        self::assertResponseStatusCodeSame(201);

        $this->send(Request::METHOD_GET, '/cards/1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [self::KEY => 'public', self::INTERNAL_KEY => 'internal'],
            array_column($this->json()['values'], 'visibility', 'key'),
        );
    }

    public function test_a_write_to_an_internal_key_is_refused_with_its_code_and_changes_nothing(): void
    {
        $this->send(Request::METHOD_POST, '/cards', '{"title":"A card","body":"","type":"feature"}');

        $this->send(Request::METHOD_PATCH, '/cards/1', json_encode(['title' => 'Renamed', 'values' => [self::INTERNAL_KEY => true]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $body = $this->json();
        self::assertSame('validation_failed', $body['code']);
        self::assertSame([['field' => 'values.'.self::INTERNAL_KEY, 'code' => 'value_internal_key', 'message' => 'The key '.self::INTERNAL_KEY.' is internal. Only the workflow writes it.']], $body['errors']);
        $this->send(Request::METHOD_GET, '/cards/1');
        self::assertSame('A card', $this->json()['title']);
        self::assertNull(array_column($this->json()['values'], 'value', 'key')[self::INTERNAL_KEY]);
    }

    public function test_the_declared_keys_of_an_undeclared_key_error_leave_out_the_internal_ones(): void
    {
        $this->send(Request::METHOD_POST, '/cards', '{"title":"A card","body":"","type":"feature"}');

        $this->send(Request::METHOD_PATCH, '/cards/1', '{"values":{"no-such-key":true}}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The workflow template declares no key no-such-key. Declared keys: '.self::KEY.'.', $this->json()['errors'][0]['message']);
    }

    public function test_a_public_key_is_still_written(): void
    {
        $this->send(Request::METHOD_POST, '/cards', '{"title":"A card","body":"","type":"feature"}');

        $this->send(Request::METHOD_PATCH, '/cards/1', json_encode(['values' => [self::KEY => true]], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(array_column($this->json()['values'], 'value', 'key')[self::KEY]);
    }

    private function send(string $method, string $path, string $body = ''): void
    {
        $this->client->request(
            $method,
            '/api/v1/projects/'.$this->project->id.$path,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
            content: $body,
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
