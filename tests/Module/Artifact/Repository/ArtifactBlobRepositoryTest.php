<?php

declare(strict_types=1);

namespace App\Tests\Module\Artifact\Repository;

use App\Module\Artifact\Entity\ArtifactBlob;
use App\Module\Artifact\Repository\ArtifactBlobRepository;
use App\Tests\Module\Artifact\ArtifactScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArtifactBlobRepositoryTest extends KernelTestCase
{
    use ArtifactScenario;

    public function test_the_same_content_in_one_project_is_stored_once(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'blob-once@example.com', 'Blob Once');

        $first = $this->repository()->findOrCreate($project, '<h1>Hello</h1>');
        $second = $this->repository()->findOrCreate($project, '<h1>Hello</h1>');

        self::assertSame($first, $second);
        self::assertSame(hash('sha256', '<h1>Hello</h1>'), $first->sha256);
        self::assertSame(14, $first->size);
        self::assertSame(1, $this->repository()->count(['project' => $project]));
    }

    public function test_the_same_content_in_two_projects_is_stored_in_each(): void
    {
        self::bootKernel();
        $em = $this->em();
        $one = $this->project($em, 'blob-two-a@example.com', 'Blob Two A');
        $two = $this->project($em, 'blob-two-b@example.com', 'Blob Two B');

        $first = $this->repository()->findOrCreate($one, 'body{}');
        $second = $this->repository()->findOrCreate($two, 'body{}');

        self::assertNotSame($first->id?->toRfc4122(), $second->id?->toRfc4122());
        self::assertSame($first->sha256, $second->sha256);
    }

    public function test_binary_content_survives_a_reload(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'blob-binary@example.com', 'Blob Binary');
        $bytes = "\x89PNG\r\n\x1a\n\x00\x01\xff";

        $id = $this->repository()->findOrCreate($project, $bytes)->id;
        $em->clear();

        $loaded = $em->find(ArtifactBlob::class, $id);
        self::assertInstanceOf(ArtifactBlob::class, $loaded);
        self::assertSame($bytes, $loaded->contents());
        self::assertSame(\strlen($bytes), $loaded->size);
    }

    public function test_find_one_by_hash_answers_null_for_a_missing_hash(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, 'blob-missing@example.com', 'Blob Missing');

        self::assertNull($this->repository()->findOneByHash($project, hash('sha256', 'absent')));
    }

    private function repository(): ArtifactBlobRepository
    {
        return static::getContainer()->get(ArtifactBlobRepository::class);
    }
}
