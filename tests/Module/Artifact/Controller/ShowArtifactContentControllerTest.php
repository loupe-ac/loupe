<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Module\Artifact\Service\ArtifactContentSigner;
use App\Tests\Module\Artifact\ArtifactScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShowArtifactContentControllerTest extends WebTestCase
{
    use ArtifactScenario;

    public function test_a_signed_link_serves_the_file_to_a_client_with_no_session(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-ok@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => '<script>1</script>', 'css/app.css' => 'body{}']]);
        $path = $this->signedPath($this->versionNumbered($artifact, 1), 'index.html');
        $this->em()->clear();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertSame('<script>1</script>', $client->getResponse()->getContent());
        $headers = $client->getResponse()->headers;
        self::assertStringStartsWith('text/html', (string) $headers->get('Content-Type'));
        $policy = (string) $headers->get('Content-Security-Policy');
        self::assertCount(1, $headers->all('content-security-policy'));
        self::assertStringStartsWith("sandbox allow-scripts allow-forms allow-popups allow-pointer-lock; frame-ancestors 'self'; default-src 'self' data: blob: 'unsafe-inline' 'unsafe-eval' https://fonts.googleapis.com", $policy);
        self::assertStringEndsWith("; form-action 'self'", $policy);
        self::assertStringNotContainsString('allow-same-origin', $policy);
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('*', $headers->get('Access-Control-Allow-Origin'));
        self::assertTrue($headers->hasCacheControlDirective('private'));
        self::assertCount(0, $headers->getCookies(), 'the response sets no cookie');
        self::assertFalse($headers->has('Set-Cookie'));
        self::assertCount(0, $client->getCookieJar()->all(), 'the client keeps no session cookie');
    }

    public function test_a_page_loads_its_other_files_through_the_same_signed_prefix(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-nested@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => '<link href="css/app.css">', 'css/app.css' => 'body{}']]);
        $path = $this->signedPath($this->versionNumbered($artifact, 1), 'index.html');
        $this->em()->clear();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, substr($path, 0, -\strlen('index.html')).'css/app.css');

        self::assertResponseIsSuccessful();
        self::assertSame('body{}', $client->getResponse()->getContent());
        self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function test_a_signed_in_browser_gets_the_same_answer_and_no_cookie_back(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-user@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $path = $this->signedPath($this->versionNumbered($artifact, 1), 'index.html');
        $this->em()->clear();

        $client->loginUser($project->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function test_a_wrong_signature_an_expired_link_or_another_version_answers_404(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-bad@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'one'], ['index.html' => 'two']]);
        $first = (string) $this->versionNumbered($artifact, 1)->id;
        $second = (string) $this->versionNumbered($artifact, 2)->id;
        $signer = $this->signer();
        $this->em()->clear();

        $future = time() + 600;
        $good = $signer->sign($first, $future);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/artifact-content/'.$first.'/'.$future.'/'.$good.'/index.html');
        self::assertResponseIsSuccessful();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/artifact-content/'.$first.'/'.$future.'/'.strrev($good).'/index.html');
        self::assertResponseStatusCodeSame(404, 'tampered signature');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/artifact-content/'.$first.'/'.($future + 1).'/'.$good.'/index.html');
        self::assertResponseStatusCodeSame(404, 'stretched expiry');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/artifact-content/'.$second.'/'.$future.'/'.$good.'/index.html');
        self::assertResponseStatusCodeSame(404, 'signature of another version');

        $past = time() - 10;
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/artifact-content/'.$first.'/'.$past.'/'.$signer->sign($first, $past).'/index.html');
        self::assertResponseStatusCodeSame(404, 'expired link with a correct signature');
    }

    /** @return iterable<string, array{string}> */
    public static function badPaths(): iterable
    {
        yield 'unknown file' => ['missing.html'];
        yield 'dot dot' => ['../index.html'];
        yield 'dot dot in the middle' => ['css/../index.html'];
        yield 'encoded dot dot' => ['%2e%2e/index.html'];
        yield 'encoded slash' => ['css%2f..%2findex.html'];
        yield 'leading slash' => ['/index.html'];
        yield 'directory' => ['css'];
    }

    #[DataProvider('badPaths')]
    public function test_a_path_that_is_not_a_stored_file_answers_404(string $path): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-path-'.md5($path).'@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x', 'css/app.css' => 'y']]);
        $base = $this->signedPath($this->versionNumbered($artifact, 1), 'index.html');
        $this->em()->clear();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, substr($base, 0, -\strlen('index.html')).$path);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_file_of_another_project_is_out_of_reach_with_a_valid_link(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-own@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $stranger = $this->project($this->em(), 'content-stranger@example.com', 'Stranger');
        $secret = $this->storedArtifact($stranger, 'Secret', [['secret.html' => 'hidden']]);
        $base = $this->signedPath($this->versionNumbered($artifact, 1), 'index.html');
        $this->em()->clear();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, substr($base, 0, -\strlen('index.html')).'secret.html');
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($secret->id);
    }

    public function test_with_no_content_domain_a_sub_address_link_serves_nothing(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-unset@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $versionId = (string) $this->versionNumbered($artifact, 1)->id;
        $label = str_replace('-', '', (string) $artifact->id);
        $signer = $this->signer();
        $expires = time() + 600;
        $this->em()->clear();

        foreach (['artifact-content.invalid', 'content.example'] as $host) {
            foreach ([$signer->sign($versionId, $expires, $label), $signer->sign($versionId, $expires)] as $signature) {
                $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, \sprintf('https://%s.%s/%s/%d/%s/index.html', $label, $host, $versionId, $expires, $signature));
                self::assertNotSame(200, $client->getResponse()->getStatusCode());
                self::assertNotSame('x', $client->getResponse()->getContent());
            }
        }
    }

    public function test_it_answers_404_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('content-off@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $path = $this->signedPath($this->versionNumbered($artifact, 1), 'index.html');
        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = 'artifacts.enabled'");
        $this->em()->clear();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $path);

        self::assertResponseStatusCodeSame(404);
    }

    private function signer(): ArtifactContentSigner
    {
        $signer = static::getContainer()->get(ArtifactContentSigner::class);
        self::assertInstanceOf(ArtifactContentSigner::class, $signer);

        return $signer;
    }

    private function signedPath(object $version, string $path): string
    {
        self::assertInstanceOf(\App\Module\Artifact\Entity\ArtifactVersion::class, $version);

        return $this->signer()->linkFor($version, $path);
    }
}
