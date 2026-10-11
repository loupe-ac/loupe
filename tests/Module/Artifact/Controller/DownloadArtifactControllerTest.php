<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DownloadArtifactControllerTest extends WebTestCase
{
    use ArtifactScenario;

    public function test_it_zips_the_latest_version_and_a_pinned_one(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('download-owner@example.com');
        $artifact = $this->storedArtifact($project, 'Widget Tokens / v2!', [
            ['index.html' => 'one'],
            ['index.html' => 'two', 'css/app.css' => 'body{}'],
        ]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/download');
        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=widget-tokens-v2-v2.zip', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(['css/app.css' => 'body{}', 'index.html' => 'two'], $this->unzip($client));

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/1/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('widget-tokens-v2-v1.zip', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(['index.html' => 'one'], $this->unzip($client));
    }

    public function test_a_name_with_no_letters_still_gets_a_filename(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('download-bare@example.com');
        $artifact = $this->storedArtifact($project, '!!!', [['index.html' => 'x']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/download');

        self::assertStringContainsString('artifact-v1.zip', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function test_it_refuses_a_stranger_an_unknown_version_and_a_switched_off_flag(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('download-guard@example.com');
        $stranger = $this->project($this->em(), 'download-guard-stranger@example.com', 'Stranger');
        $artifact = $this->storedArtifact($project, 'Mine', [['index.html' => 'x']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();

        $client->loginUser($stranger->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/download');
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($project->owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/versions/9/download');
        self::assertResponseStatusCodeSame(404);

        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = 'artifacts.enabled'");
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/projects/'.$projectId.'/artifacts/'.$artifactId.'/download');
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, string> file contents by path */
    private function unzip(KernelBrowser $client): array
    {
        $file = tempnam(sys_get_temp_dir(), 'artifact-test-');
        self::assertNotFalse($file);
        file_put_contents($file, $client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $contents = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = (string) $zip->getNameIndex($index);
            $contents[$name] = (string) $zip->getFromIndex($index);
        }
        $zip->close();
        unlink($file);
        ksort($contents);

        return $contents;
    }
}
