<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Service;

use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Service\ApiKeysExporter;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiKeysExporterTest extends KernelTestCase
{
    public function test_it_lists_each_key_with_its_dates_and_never_the_value_or_the_hash(): void
    {
        self::bootKernel();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('api-key-export@example.com');
        $stranger = $scenario->createUser('api-key-export-stranger@example.com');

        $secret = ApiKey::PREFIX.'abcd'.bin2hex(random_bytes(30));
        $key = new ApiKey($user, 'CI deploy', ApiKey::hashOf($secret), ApiKey::displayStartOf($secret), new \DateTimeImmutable('2027-01-02 03:04:05'), new \DateTimeImmutable('2026-10-01 10:00:00'));
        $key->lastUsedAt = new \DateTimeImmutable('2026-10-05 11:00:00');
        $key->revokedAt = new \DateTimeImmutable('2026-10-06 12:00:00');
        $forever = new ApiKey($user, 'Dashboard', ApiKey::hashOf('other'), 'loupe_key_wxyz', null, new \DateTimeImmutable('2026-09-01 10:00:00'));
        $theirs = new ApiKey($stranger, 'Theirs', ApiKey::hashOf('theirs'), 'loupe_key_qqqq');
        foreach ([$key, $forever, $theirs] as $row) {
            $this->em()->persist($row);
        }
        $this->em()->flush();

        $exporter = static::getContainer()->get(ApiKeysExporter::class);
        self::assertInstanceOf(ApiKeysExporter::class, $exporter);
        $rows = iterator_to_array($exporter->export($user), false);

        self::assertSame('api_keys.json', $exporter->filename());
        self::assertSame([
            [
                'name' => 'CI deploy',
                'displayStart' => 'loupe_key_abcd',
                'createdAt' => '2026-10-01T10:00:00+00:00',
                'expiresAt' => '2027-01-02T03:04:05+00:00',
                'lastUsedAt' => '2026-10-05T11:00:00+00:00',
                'revokedAt' => '2026-10-06T12:00:00+00:00',
            ],
            [
                'name' => 'Dashboard',
                'displayStart' => 'loupe_key_wxyz',
                'createdAt' => '2026-09-01T10:00:00+00:00',
                'expiresAt' => null,
                'lastUsedAt' => null,
                'revokedAt' => null,
            ],
        ], $this->sortedByName($rows));

        $encoded = json_encode($rows, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $encoded);
        self::assertStringNotContainsString($key->keyHash, $encoded);
    }

    public function test_a_user_with_no_key_exports_nothing(): void
    {
        self::bootKernel();
        $user = new OAuthScenario(static::getContainer())->createUser('api-key-export-none@example.com');

        $exporter = static::getContainer()->get(ApiKeysExporter::class);
        self::assertInstanceOf(ApiKeysExporter::class, $exporter);

        self::assertSame([], iterator_to_array($exporter->export($user), false));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sortedByName(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
