<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistPick;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WaitlistExporterTest extends TestCase
{
    public function test_exports_the_join_date_the_picks_and_the_text(): void
    {
        $entry = new WaitlistEntry('alice@example.com', new \DateTimeImmutable('2026-09-01T10:00:00+00:00'));
        $entry->mergePicks([WaitlistPick::GitLab], 'Jujutsu');

        $rows = iterator_to_array(new WaitlistExporter($this->repositoryReturning($entry))->export(new User('Alice A', 'alice@example.com', 'x')));

        self::assertSame([['joinedAt' => '2026-09-01T10:00:00+00:00', 'picks' => ['gitlab'], 'other' => 'Jujutsu']], $rows);
    }

    public function test_exports_an_empty_list_when_the_user_never_joined(): void
    {
        $exporter = new WaitlistExporter($this->repositoryReturning(null));

        self::assertSame([], iterator_to_array($exporter->export(new User('Bob B', 'bob@example.com', 'x'))));
        self::assertSame('waitlist.json', $exporter->filename());
    }

    private function repositoryReturning(?WaitlistEntry $entry): WaitlistEntryRepository
    {
        /** @var WaitlistEntryRepository&Stub $repo */
        $repo = $this->createStub(WaitlistEntryRepository::class);
        $repo->method('findOneByEmail')->willReturn($entry);

        return $repo;
    }
}
