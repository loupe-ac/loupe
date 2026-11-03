<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Api\RateLimit\ApiBudget;
use Opis\JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The two routes that need no sign-in, and the answers of an API path that matches no route. */
final class PublicApiRoutesTest extends WebTestCase
{
    public function test_the_version_list_needs_no_sign_in_and_matches_its_spec_schema(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/versions', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        $body = json_decode((string) $client->getResponse()->getContent(), false, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('v1', $body->versions[0]->version);
        self::assertSame('beta', $body->versions[0]->status);
        $this->assertMatchesSchema('ApiVersions', $body);
    }

    public function test_the_public_spec_is_served_as_committed_with_no_sign_in(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/openapi.json');

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringEqualsFile(self::projectDir().'/openapi/public.json', (string) $client->getResponse()->getContent());
    }

    public function test_an_unknown_v1_route_answers_problem_json_whatever_the_accept_header_says(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/no-such-route', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('route_not_found', $body['code']);
        self::assertSame('https://loupe.ac/docs/reference/api/errors#route_not_found', $body['type']);
        $this->assertMatchesSchema('Problem', (object) json_decode((string) $client->getResponse()->getContent(), false));
    }

    public function test_a_wrong_method_on_a_route_answers_405_with_allow(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/api/versions');

        self::assertResponseStatusCodeSame(405);
        self::assertStringContainsString('GET', (string) $client->getResponse()->headers->get('Allow'));
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('method_not_allowed', $body['code']);
    }

    public function test_a_version_this_server_does_not_serve_answers_404_with_the_served_list(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v2/projects');

        self::assertResponseStatusCodeSame(404);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('api_version_unsupported', $body['code']);
        self::assertSame(['v1'], $body['versions']);
    }

    public function test_an_old_api_path_that_matches_no_route_is_not_rewritten(): void
    {
        $client = static::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/no-such-route');

        self::assertNotSame('application/problem+json', $client->getResponse()->headers->get('Content-Type'));
    }

    public function test_a_public_route_with_no_sign_in_has_the_budget_flags_seeded_by_the_migration_defaults(): void
    {
        static::createClient();

        $budget = static::getContainer()->get(ApiBudget::class);

        self::assertInstanceOf(ApiBudget::class, $budget);
        self::assertSame(600, $budget->reads());
        self::assertSame(120, $budget->writes());
    }

    private function assertMatchesSchema(string $schema, object $data): void
    {
        $spec = json_decode((string) file_get_contents(self::projectDir().'/openapi/public.json'), false, flags: \JSON_THROW_ON_ERROR);
        $document = (object) ['$ref' => '#/components/schemas/'.$schema, 'components' => $spec->components];

        $result = new Validator()->validate($data, $document);

        self::assertTrue($result->isValid(), (string) json_encode($result->error()?->message()));
    }

    private static function projectDir(): string
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($dir);

        return $dir;
    }
}
