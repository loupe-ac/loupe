<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubUserConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;
use Ubermuda\DoctrineExtra\Encryption\Encryptor;

/**
 * Runs the bundle's re-encrypt command against PostgreSQL, over the real GitHub
 * token columns, with an old key set as the previous key.
 */
final class ReencryptGitHubTokensTest extends KernelTestCase
{
    private string $oldKey;

    protected function setUp(): void
    {
        $this->oldKey = base64_encode(random_bytes(32));
        $_ENV['APP_ENCRYPTION_KEY_PREVIOUS'] = $_SERVER['APP_ENCRYPTION_KEY_PREVIOUS'] = $this->oldKey;
        putenv('APP_ENCRYPTION_KEY_PREVIOUS='.$this->oldKey);
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_ENCRYPTION_KEY_PREVIOUS'], $_SERVER['APP_ENCRYPTION_KEY_PREVIOUS']);
        putenv('APP_ENCRYPTION_KEY_PREVIOUS');
        parent::tearDown();
    }

    public function test_a_token_made_under_the_old_key_moves_to_the_current_key(): void
    {
        $connection = $this->seedConnectionUnderOldKey('old-access', 'old-refresh');

        $first = $this->reencrypt();
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());
        self::assertStringContainsString('github_user_connections', $first->getDisplay());

        $currentOnly = $this->currentKeyOnly();
        $row = $connection->fetchAssociative('SELECT access_token, refresh_token FROM github_user_connections');
        self::assertIsArray($row);
        $access = $currentOnly->decrypt($row['access_token']);
        self::assertSame('old-access', $access->plain);
        self::assertTrue($access->current);
        self::assertSame('old-refresh', $currentOnly->decrypt($row['refresh_token'])->plain);

        $second = $this->reencrypt();
        self::assertSame(0, $second->getStatusCode(), $second->getDisplay());
        self::assertSame($row, $connection->fetchAssociative('SELECT access_token, refresh_token FROM github_user_connections'), 'A second run changes nothing.');
    }

    public function test_a_value_that_no_key_opens_fails_the_run_and_stays_as_it_is(): void
    {
        $connection = $this->seedConnectionUnderOldKey('old-access', 'old-refresh');
        $foreign = new Encryptor(new EncryptionKeyProvider([base64_encode(random_bytes(32))]))->encrypt('lost');
        $connection->executeStatement('UPDATE github_user_connections SET refresh_token = :value', ['value' => $foreign]);

        $tester = $this->reencrypt();

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertSame($foreign, $connection->fetchOne('SELECT refresh_token FROM github_user_connections'));
        self::assertSame('old-access', $this->currentKeyOnly()->decrypt((string) $connection->fetchOne('SELECT access_token FROM github_user_connections'))->plain);
    }

    private function seedConnectionUnderOldKey(string $access, string $refresh): Connection
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(fullName: 'Riley', email: 'reencrypt-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($user);
        $em->persist(new GitHubUserConnection(
            user: $user,
            githubUserId: 4_242,
            login: 'riley',
            accessToken: 'placeholder',
            refreshToken: 'placeholder',
            accessTokenExpiresAt: new \DateTimeImmutable('+8 hours'),
            refreshTokenExpiresAt: new \DateTimeImmutable('+6 months'),
        ));
        $em->flush();

        $oldKeyOnly = new Encryptor(new EncryptionKeyProvider([$this->oldKey]));
        $connection = $em->getConnection();
        $connection->executeStatement(
            'UPDATE github_user_connections SET access_token = :access, refresh_token = :refresh',
            ['access' => $oldKeyOnly->encrypt($access), 'refresh' => $oldKeyOnly->encrypt($refresh)],
        );

        return $connection;
    }

    private function reencrypt(): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('doctrine-extra:reencrypt'));
        $tester->execute([]);

        return $tester;
    }

    private function currentKeyOnly(): Encryptor
    {
        return new Encryptor(new EncryptionKeyProvider([(string) $_SERVER['APP_ENCRYPTION_KEY']]));
    }
}
