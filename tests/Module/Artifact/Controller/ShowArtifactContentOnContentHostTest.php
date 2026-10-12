<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Module\Artifact\Service\ArtifactContentHost;
use App\Module\Artifact\Service\ArtifactContentSigner;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowArtifactContentOnContentHostTest extends WebTestCase
{
    use ArtifactScenario;

    private const string HOST = 'content.example';

    private string|false $previousHost;

    #[\Override]
    protected function setUp(): void
    {
        $this->previousHost = getenv('ARTIFACT_CONTENT_HOST');
        $this->setHost(self::HOST);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setHost(false === $this->previousHost ? '' : $this->previousHost);
        parent::tearDown();
    }

    public function test_a_link_on_the_right_sub_address_serves_the_file_with_the_content_policy(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('host-ok@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => '<script>1</script>']]);
        $link = $this->signer()->linkFor($this->versionNumbered($artifact, 1), 'index.html');
        $label = $this->label($artifact->id);
        $this->em()->clear();

        self::assertStringStartsWith('https://'.$label.'.'.self::HOST.'/', $link);
        $client->request(Request::METHOD_GET, $link);

        self::assertResponseIsSuccessful();
        self::assertSame('<script>1</script>', $client->getResponse()->getContent());
        $headers = $client->getResponse()->headers;
        $policy = (string) $headers->get('Content-Security-Policy');
        self::assertStringStartsWith('sandbox allow-scripts allow-forms allow-popups allow-pointer-lock allow-same-origin; frame-ancestors http://localhost; default-src', $policy);
        self::assertStringEndsWith("; form-action 'self'", $policy);
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('*', $headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($headers->has('Set-Cookie'));
    }

    public function test_another_sub_address_answers_404_even_with_the_signature_of_the_link(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('host-wrong@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $other = $this->storedArtifact($project, 'Other', [['index.html' => 'y']]);
        $link = $this->signer()->linkFor($this->versionNumbered($artifact, 1), 'index.html');
        $otherLabel = $this->label($other->id);
        $label = $this->label($artifact->id);
        $this->em()->clear();

        $client->request(Request::METHOD_GET, str_replace($label, $otherLabel, $link));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_label_signed_for_another_artifact_answers_404(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('host-forged@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $other = $this->storedArtifact($project, 'Other', [['index.html' => 'y']]);
        $version = $this->versionNumbered($artifact, 1);
        $versionId = (string) $version->id;
        $otherLabel = $this->label($other->id);
        $expires = time() + 600;
        $signature = $this->signer()->sign($versionId, $expires, $otherLabel);
        $this->em()->clear();

        $client->request(Request::METHOD_GET, \sprintf('https://%s.%s/%s/%d/%s/index.html', $otherLabel, self::HOST, $versionId, $expires, $signature));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_sealed_link_does_not_work_on_a_sub_address(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('host-sealed@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $versionId = (string) $this->versionNumbered($artifact, 1)->id;
        $label = $this->label($artifact->id);
        $expires = time() + 600;
        $signature = $this->signer()->sign($versionId, $expires);
        $this->em()->clear();

        $client->request(Request::METHOD_GET, \sprintf('https://%s.%s/%s/%d/%s/index.html', $label, self::HOST, $versionId, $expires, $signature));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_content_domain_serves_nothing_but_stored_files(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, 'https://'.str_repeat('a', 32).'.'.self::HOST.'/login');
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, 'https://'.self::HOST.'/login');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_app_domain_keeps_serving_its_pages_and_the_sealed_route(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('host-app@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $versionId = (string) $this->versionNumbered($artifact, 1)->id;
        $expires = time() + 600;
        $signature = $this->signer()->sign($versionId, $expires);
        $this->em()->clear();

        $client->request(Request::METHOD_GET, '/login');
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, \sprintf('/artifact-content/%s/%d/%s/index.html', $versionId, $expires, $signature));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('allow-same-origin', (string) $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function setHost(string $host): void
    {
        putenv('' === $host ? 'ARTIFACT_CONTENT_HOST' : 'ARTIFACT_CONTENT_HOST='.$host);
        $_ENV['ARTIFACT_CONTENT_HOST'] = $host;
        $_SERVER['ARTIFACT_CONTENT_HOST'] = $host;
    }

    private function signer(): ArtifactContentSigner
    {
        $signer = static::getContainer()->get(ArtifactContentSigner::class);
        self::assertInstanceOf(ArtifactContentSigner::class, $signer);

        return $signer;
    }

    private function label(mixed $artifactId): string
    {
        $host = static::getContainer()->get(ArtifactContentHost::class);
        self::assertInstanceOf(ArtifactContentHost::class, $host);

        return $host->labelOfId((string) $artifactId);
    }
}
