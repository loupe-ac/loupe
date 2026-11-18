<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service;

use App\Module\OAuth\Entity\WorkerRunCredential;
use App\Module\OAuth\Service\WorkerRunCredentialsExporter;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunCredentialsExporterTest extends KernelTestCase
{
    public function test_it_lists_the_records_of_the_user_and_never_the_token_id(): void
    {
        self::bootKernel();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('run-credential-export@example.com');
        $stranger = $scenario->createUser('run-credential-export-stranger@example.com');
        $project = $scenario->createProject($user, 'Export');
        $strangerProject = $scenario->createProject($stranger, 'Theirs');

        $bridge = Uuid::v7();
        $runKey = Uuid::v7();
        $session = Uuid::v7();
        $mine = new WorkerRunCredential('secret-token-id-mine', $user, $project, $bridge, $runKey, $session, new \DateTimeImmutable('2026-10-01 11:00:00'), new \DateTimeImmutable('2026-10-01 10:00:00'), new \DateTimeImmutable('2026-10-01 10:30:00'));
        $theirs = new WorkerRunCredential('secret-token-id-theirs', $stranger, $strangerProject, Uuid::v7(), Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-10-01 11:00:00'));
        $em = $this->em();
        $em->persist($mine);
        $em->persist($theirs);
        $em->flush();

        $exporter = static::getContainer()->get(WorkerRunCredentialsExporter::class);
        self::assertInstanceOf(WorkerRunCredentialsExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($user), false);

        self::assertSame('worker_run_credentials.json', $exporter->filename());
        self::assertSame([[
            'projectId' => (string) $project->id,
            'bridgeId' => $bridge->toRfc4122(),
            'runKey' => $runKey->toRfc4122(),
            'sessionId' => $session->toRfc4122(),
            'issuedAt' => '2026-10-01T10:00:00+00:00',
            'expiresAt' => '2026-10-01T11:00:00+00:00',
            'revokedAt' => '2026-10-01T10:30:00+00:00',
        ]], $rows);
        self::assertStringNotContainsString('secret-token-id', json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function test_a_user_with_no_record_exports_nothing(): void
    {
        self::bootKernel();
        $user = new OAuthScenario(static::getContainer())->createUser('run-credential-export-none@example.com');

        $exporter = static::getContainer()->get(WorkerRunCredentialsExporter::class);
        self::assertInstanceOf(WorkerRunCredentialsExporter::class, $exporter);

        self::assertSame([], iterator_to_array($exporter->export($user), false));
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
