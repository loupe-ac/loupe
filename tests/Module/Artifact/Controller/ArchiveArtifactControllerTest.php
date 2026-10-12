<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Controller;

use App\Module\Artifact\Entity\Artifact;
use App\Module\Artifact\Repository\ArtifactRepository;
use App\Tests\Module\Artifact\ArtifactScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ArchiveArtifactControllerTest extends WebTestCase
{
    use ArtifactScenario;

    private const array HEADERS = ['HTTP_REFERER' => 'http://localhost/'];

    public function test_the_owner_archives_and_restores_an_artifact(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('archive-owner@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->path($projectId, $artifactId, 'archive'), ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.'/artifacts/'.$artifactId);
        $this->em()->clear();
        self::assertInstanceOf(\DateTimeImmutable::class, $this->reload($artifactId)->archivedAt);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->path($projectId, $artifactId, 'unarchive'), ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseRedirects('/projects/'.$projectId.'/artifacts/'.$artifactId);
        $this->em()->clear();
        self::assertNull($this->reload($artifactId)->archivedAt);
    }

    public function test_archiving_twice_keeps_the_first_time(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('archive-twice@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $artifact->archivedAt = new \DateTimeImmutable('2026-01-02 03:04:05');
        $this->em()->flush();
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->path($projectId, $artifactId, 'archive'), ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame('2026-01-02 03:04:05', $this->reload($artifactId)->archivedAt?->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{string}> */
    public static function actions(): iterable
    {
        yield 'archive' => ['archive'];
        yield 'unarchive' => ['unarchive'];
    }

    #[DataProvider('actions')]
    public function test_a_bad_token_is_refused_with_403_and_changes_nothing(string $action): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('archive-csrf-'.$action.'@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        $archivedAt = new \DateTimeImmutable('2026-01-02 03:04:05');
        if ('unarchive' === $action) {
            $artifact->archivedAt = $archivedAt;
            $this->em()->flush();
        }
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->path($projectId, $artifactId, $action), ['_csrf_token' => 'invalid-token'], [], self::HEADERS);

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertSame('unarchive' === $action ? $archivedAt->format('c') : null, $this->reload($artifactId)->archivedAt?->format('c'));
    }

    #[DataProvider('actions')]
    public function test_another_user_gets_a_403_even_with_a_valid_token(string $action): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('archive-guard-'.$action.'@example.com');
        $stranger = $this->project($this->em(), 'archive-guard-stranger-'.$action.'@example.com', 'Stranger');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($stranger->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->path($projectId, $artifactId, $action), ['_csrf_token' => 'csrf-token'], [], self::HEADERS);

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->reload($artifactId)->archivedAt);
    }

    public function test_a_get_is_refused_and_a_switched_off_flag_answers_404(): void
    {
        $client = static::createClient();
        $project = $this->switchedOnProject('archive-flag@example.com');
        $artifact = $this->storedArtifact($project, 'Prototype', [['index.html' => 'x']]);
        [$projectId, $artifactId] = [(string) $project->id, (string) $artifact->id];
        $this->em()->clear();
        $client->loginUser($project->owner);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->path($projectId, $artifactId, 'archive'));
        self::assertResponseStatusCodeSame(405);

        $this->em()->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = 'artifacts.enabled'");
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, $this->path($projectId, $artifactId, 'archive'), ['_csrf_token' => 'csrf-token'], [], self::HEADERS);
        self::assertResponseStatusCodeSame(404);
    }

    private function path(string $projectId, string $artifactId, string $action): string
    {
        return '/projects/'.$projectId.'/artifacts/'.$artifactId.'/'.$action;
    }

    private function reload(string $artifactId): Artifact
    {
        $repository = static::getContainer()->get(ArtifactRepository::class);
        self::assertInstanceOf(ArtifactRepository::class, $repository);

        return $repository->find($artifactId) ?? throw new \LogicException('The artifact is gone.');
    }
}
