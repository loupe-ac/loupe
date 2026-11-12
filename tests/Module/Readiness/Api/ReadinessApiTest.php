<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Api;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\ApiKeyCredential;
use App\Tests\Support\PublicSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** GET and PATCH /api/v1/projects/{project}/readiness. */
final class ReadinessApiTest extends WebTestCase
{
    use DiscoveryScenario;

    private KernelBrowser $client;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->project = $this->workflowProject('readiness-api');
        $this->token = $this->tokenFor($this->project->owner);
    }

    public function test_it_reads_the_checks_and_reports_that_discovery_never_ran(): void
    {
        $this->send(Request::METHOD_GET);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        PublicSpec::assertMatches('Readiness', $body);
        self::assertTrue($body['guideShown']);
        self::assertNull($body['discovery']);
        self::assertNotSame([], $body['rows']);
        foreach ($body['rows'] as $row) {
            self::assertSame(['key', 'done', 'status'], array_keys($row));
            self::assertNotSame('', $row['status']);
        }
        self::assertTrue($this->client->getResponse()->headers->has('RateLimit-Limit'));
    }

    public function test_it_reads_the_latest_discovery_run(): void
    {
        $card = $this->discoveryCard($this->project);
        $run = $this->discoveryRun($card, DiscoveryRunState::Requested);

        $this->send(Request::METHOD_GET);

        $discovery = $this->json()['discovery'];
        PublicSpec::assertMatches('ReadinessDiscovery', $discovery);
        self::assertSame((string) $run->id, $discovery['runId']);
        self::assertSame((string) $card->id, $discovery['cardId']);
        self::assertSame($card->number, $discovery['cardNumber']);
        self::assertSame('requested', $discovery['state']);
        self::assertNull($discovery['reason']);
    }

    public function test_the_checks_show_while_the_guide_is_hidden(): void
    {
        $this->send(Request::METHOD_PATCH, '{"guideShown":false}');
        $this->send(Request::METHOD_GET);

        $body = $this->json();
        self::assertFalse($body['guideShown']);
        self::assertNotSame([], $body['rows']);
    }

    public function test_it_hides_and_shows_the_guide(): void
    {
        $this->send(Request::METHOD_PATCH, '{"guideShown":false}');

        self::assertResponseStatusCodeSame(200);
        $hidden = $this->json();
        PublicSpec::assertMatches('ReadinessGuide', $hidden);
        self::assertFalse($hidden['shown']);
        self::assertIsString($hidden['hiddenAt']);
        self::assertNotNull($this->stored()->readinessGuideHiddenAt);

        $this->send(Request::METHOD_PATCH, '{"guideShown":true}');

        self::assertSame(['shown' => true, 'hiddenAt' => null], $this->json());
        self::assertNull($this->stored()->readinessGuideHiddenAt);
    }

    public function test_hiding_the_guide_again_keeps_the_first_time(): void
    {
        $this->send(Request::METHOD_PATCH, '{"guideShown":false}');
        $first = $this->json()['hiddenAt'];

        $this->send(Request::METHOD_PATCH, '{"guideShown":false}');

        self::assertSame($first, $this->json()['hiddenAt']);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedBodies(): iterable
    {
        yield 'missing' => ['{}'];
        yield 'null' => ['{"guideShown":null}'];
        yield 'text' => ['{"guideShown":"no"}'];
    }

    #[DataProvider('refusedBodies')]
    public function test_a_body_without_a_boolean_is_refused(string $body): void
    {
        $this->send(Request::METHOD_PATCH, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->json()['code']);
        self::assertNull($this->stored()->readinessGuideHiddenAt);
    }

    public function test_a_stranger_does_not_find_the_project_and_no_credential_is_refused(): void
    {
        $stranger = new User(fullName: 'Stranger', email: 'readiness-api-stranger-'.uniqid().'@example.com', password: 'hashed');
        $this->em()->persist($stranger);
        $this->em()->flush();
        $strangerToken = $this->tokenFor($stranger);

        foreach ([[Request::METHOD_GET, ''], [Request::METHOD_PATCH, '{"guideShown":false}']] as [$method, $body]) {
            $this->send($method, $body, $strangerToken);
            self::assertResponseStatusCodeSame(404, $method);
            self::assertSame('project_not_found', $this->json()['code']);

            $this->send($method, $body, null);
            self::assertResponseStatusCodeSame(401, $method);
        }
        self::assertNull($this->stored()->readinessGuideHiddenAt);
    }

    private function stored(): Project
    {
        $this->em()->clear();

        return $this->em()->find(Project::class, $this->project->id) ?? throw new \LogicException('The project is gone.');
    }

    private function send(string $method, string $body = '', ?string $token = ''): void
    {
        $bearer = '' === $token ? $this->token : $token;
        $this->client->request($method, '/api/v1/projects/'.$this->project->id.'/readiness', server: ['CONTENT_TYPE' => 'application/json'] + (null === $bearer ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]), content: $body);
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
