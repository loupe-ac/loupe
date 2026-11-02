<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\Service\OpenRouterKeyAccountPurger;
use App\Module\OpenRouter\Service\OpenRouterKeyExporter;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenRouterKeyAccountDataTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_the_purger_deletes_only_the_account_key_of_the_user_and_runs_after_the_project_purger(): void
    {
        self::bootKernel();
        $mine = $this->makeKey(self::getContainer(), $this->makeUser(self::getContainer(), 'purge-mine'));
        $theirs = $this->makeKey(self::getContainer(), $this->makeUser(self::getContainer(), 'purge-theirs'));
        $projectPurger = self::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(ProjectAccountPurger::class, $projectPurger);
        self::assertNotNull($mine->user);

        self::assertGreaterThan($projectPurger->deletionOrder(), $this->purger()->deletionOrder());
        $this->purger()->purge($mine->user, new AccountDeletionCleanup());

        $this->em()->clear();
        self::assertNull($this->em()->find(OpenRouterKey::class, $mine->id));
        self::assertNotNull($this->em()->find(OpenRouterKey::class, $theirs->id));
    }

    public function test_the_purger_reads_the_user_id_as_a_scalar_so_a_detached_user_works(): void
    {
        self::bootKernel();
        $key = $this->makeKey(self::getContainer(), $this->makeUser(self::getContainer(), 'purge-detached'));
        self::assertNotNull($key->user);
        $user = $key->user;
        $this->em()->clear();

        $this->purger()->purge($user, new AccountDeletionCleanup());

        self::assertNull($this->em()->find(OpenRouterKey::class, $key->id));
    }

    public function test_the_export_holds_whether_a_key_exists_and_its_dates_and_never_the_key(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'export');
        $project = $this->makeProject(self::getContainer(), $user);
        $account = $this->makeKey(self::getContainer(), $user, 'sk-or-v1-the-account-secret-wxyz');
        $account->state = OpenRouterKeyState::Refused;
        $account->refusedSince = new \DateTimeImmutable('2026-10-29 08:00:00 UTC');
        $account->lastReadAt = new \DateTimeImmutable('2026-10-28 08:00:00 UTC');
        $this->makeKey(self::getContainer(), $project, 'sk-or-v1-the-project-secret-7c21');
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement('UPDATE openrouter_keys SET secret = :junk', ['junk' => base64_encode(random_bytes(64))]);
        $this->em()->clear();

        $rows = [...$this->exporter()->export($user)];

        self::assertSame('openrouter_keys.json', $this->exporter()->filename());
        self::assertCount(2, $rows);
        self::assertSame([
            'scope' => 'account',
            'projectId' => null,
            'state' => 'refused',
            'createdAt' => $account->createdAt->format(\DateTimeInterface::ATOM),
            'refusedSince' => '2026-10-29T08:00:00+00:00',
            'lastReadAt' => '2026-10-28T08:00:00+00:00',
        ], $rows[0]);
        self::assertSame('project', $rows[1]['scope']);
        self::assertSame((string) $project->id, $rows[1]['projectId']);
        $json = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $json);
        self::assertStringNotContainsString('wxyz', $json);
    }

    public function test_the_export_of_a_user_without_a_key_is_empty_and_leaves_out_other_users(): void
    {
        self::bootKernel();
        $stranger = $this->makeUser(self::getContainer(), 'export-stranger');
        $this->makeKey(self::getContainer(), $stranger);
        $this->makeKey(self::getContainer(), $this->makeProject(self::getContainer(), $stranger));

        self::assertSame([], [...$this->exporter()->export($this->makeUser(self::getContainer(), 'export-empty'))]);
    }

    private function purger(): OpenRouterKeyAccountPurger
    {
        $purger = self::getContainer()->get(OpenRouterKeyAccountPurger::class);
        self::assertInstanceOf(OpenRouterKeyAccountPurger::class, $purger);

        return $purger;
    }

    private function exporter(): OpenRouterKeyExporter
    {
        $exporter = self::getContainer()->get(OpenRouterKeyExporter::class);
        self::assertInstanceOf(OpenRouterKeyExporter::class, $exporter);

        return $exporter;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
