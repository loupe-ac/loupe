<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Command;

use App\Module\GitHub\Command\BackfillInstallationAccountsCommand;
use App\Module\GitHub\Command\BackfillInstallationAccountsHandler;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BackfillInstallationAccountsHandlerTest extends KernelTestCase
{
    public function test_it_fills_the_account_id_of_each_listed_record_and_leaves_an_unlisted_one_empty(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $em->getConnection()->executeStatement('DELETE FROM github_installations');
        $em->persist(new GitHubInstallation(8_300_001, 'acme', GitHubRepositorySelection::All));
        $em->persist(new GitHubInstallation(8_300_002, 'gone', GitHubRepositorySelection::All));
        $em->persist(new GitHubInstallation(8_300_003, 'known', GitHubRepositorySelection::All, 9));
        $em->flush();

        $result = $this->handler($em, $installations, [['id' => 8_300_001, 'account' => ['login' => 'acme', 'id' => 4_301]], ['id' => 8_300_003, 'account' => ['login' => 'known', 'id' => 4_303]]])(new BackfillInstallationAccountsCommand());

        $em->clear();
        self::assertSame(1, $result->filled);
        self::assertSame(1, $result->unlisted);
        self::assertSame(4_301, $installations->findOneByInstallationId(8_300_001)?->accountId);
        self::assertNull($installations->findOneByInstallationId(8_300_002)?->accountId);
        self::assertSame(9, $installations->findOneByInstallationId(8_300_003)?->accountId, 'A known account id is kept.');
    }

    public function test_it_calls_github_only_when_a_record_lacks_an_account_id(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);
        $em->getConnection()->executeStatement('DELETE FROM github_installations');

        $result = $this->handler($em, $installations, null)(new BackfillInstallationAccountsCommand());

        self::assertSame(0, $result->filled);
        self::assertSame(0, $result->unlisted);
    }

    /** @param ?list<array<string, mixed>> $listed null when no request may happen */
    private function handler(EntityManagerInterface $em, GitHubInstallationRepository $installations, ?array $listed): BackfillInstallationAccountsHandler
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        $http = new MockHttpClient(static fn (): MockResponse => null === $listed
            ? throw new \LogicException('No request expected.') : new MockResponse(json_encode($listed, \JSON_THROW_ON_ERROR), ['http_code' => 200]), 'https://api.github.com');
        $api = new GitHubAppApi($http, new GitHubAppConfiguration(null, null, null, null, '123456', $pem), new MockClock('2026-11-10 12:00:00'));

        return new BackfillInstallationAccountsHandler($installations, $api, $em, new NullLogger());
    }
}
