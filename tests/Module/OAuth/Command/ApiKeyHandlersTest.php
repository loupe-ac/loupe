<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\CreateApiKeyCommand;
use App\Module\OAuth\Command\CreateApiKeyHandler;
use App\Module\OAuth\Command\CreateApiKeyView;
use App\Module\OAuth\Command\ListApiKeysCommand;
use App\Module\OAuth\Command\ListApiKeysHandler;
use App\Module\OAuth\Command\RemoveApiKeyCommand;
use App\Module\OAuth\Command\RemoveApiKeyHandler;
use App\Module\OAuth\Command\RevokeApiKeyCommand;
use App\Module\OAuth\Command\RevokeApiKeyHandler;
use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\ApiKeyExpiry;
use App\Module\OAuth\Event\ApiKeyCreated;
use App\Module\OAuth\Repository\ApiKeyRepository;
use App\Tests\Support\OAuthScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\AuditOutcome;

final class ApiKeyHandlersTest extends KernelTestCase
{
    private const string NOW = '2026-11-01 12:00:00';

    private EntityManagerInterface $em;
    private MockClock $clock;
    private RecordingAuditor $audit;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->clock = new MockClock(self::NOW);
        $container->set(ClockInterface::class, $this->clock);
        $this->audit = RecordingAuditor::installedIn($container);
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = new OAuthScenario($container)->createUser('api-key-owner@example.com');
    }

    public function test_create_returns_the_whole_key_once_and_stores_only_its_hash(): void
    {
        $view = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::NinetyDays);

        self::assertStringStartsWith('loupe_key_', $view->secret);
        self::assertSame(10 + 64, \strlen($view->secret));
        self::assertSame(hash('sha256', $view->secret), $view->key->keyHash);
        self::assertSame(substr($view->secret, 0, 14), $view->key->displayStart);
        self::assertSame('2027-01-30 12:00:00', $view->key->expiresAt?->format('Y-m-d H:i:s'));

        $this->em->clear();
        $stored = $this->em->find(ApiKey::class, $view->key->id);
        self::assertNotNull($stored);
        self::assertNull($stored->revokedAt);
        self::assertNull($stored->lastUsedAt);
        self::assertNull($stored->expiryNoticeSentAt);
        $columns = $this->em->getConnection()->fetchAssociative('SELECT * FROM api_keys WHERE id = :id', ['id' => (string) $view->key->id]);
        self::assertIsArray($columns);
        self::assertNotContains($view->secret, $columns);
    }

    public function test_create_gives_each_key_its_own_secret(): void
    {
        self::assertNotSame($this->createFor($this->owner, 'a', ApiKeyExpiry::Never)->secret, $this->createFor($this->owner, 'b', ApiKeyExpiry::Never)->secret);
    }

    public function test_create_maps_each_expiry_choice_to_an_end_date(): void
    {
        self::assertSame('2026-12-01', $this->createFor($this->owner, 'k', ApiKeyExpiry::ThirtyDays)->key->expiresAt?->format('Y-m-d'));
        self::assertSame('2027-11-01', $this->createFor($this->owner, 'k', ApiKeyExpiry::OneYear)->key->expiresAt?->format('Y-m-d'));
        self::assertNull($this->createFor($this->owner, 'k', ApiKeyExpiry::Never)->key->expiresAt);
    }

    public function test_create_trims_the_name(): void
    {
        self::assertSame('CI server', $this->createFor($this->owner, '  CI server ', ApiKeyExpiry::Never)->key->name);
    }

    public function test_create_refuses_a_blank_name_and_a_name_over_one_hundred_characters(): void
    {
        foreach (['', '   ', str_repeat('a', 101)] as $name) {
            try {
                $this->createFor($this->owner, $name, ApiKeyExpiry::Never);
                self::fail('a name of '.\strlen($name).' characters was accepted');
            } catch (DomainErrors $e) {
                self::assertSame(['name' => 'oauth.api_key.error.name_length'], $e->errors);
            }
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_keys'));
    }

    public function test_create_accepts_a_name_of_one_hundred_characters(): void
    {
        self::assertSame(100, mb_strlen($this->createFor($this->owner, str_repeat('é', 100), ApiKeyExpiry::Never)->key->name));
    }

    public function test_create_records_an_audit_entry_without_the_key_or_the_name(): void
    {
        $view = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::NinetyDays);

        $event = $this->audit->record('oauth.api_key_created');
        self::assertSame(AuditOutcome::Success, $event->outcome);
        self::assertSame((string) $view->key->id, $event->subject?->id);
        self::assertStringNotContainsString($view->secret, json_encode($event->context, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('CI server', json_encode($event->context, \JSON_THROW_ON_ERROR));
    }

    public function test_create_announces_the_new_key_after_the_commit(): void
    {
        $seen = [];
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $em = $this->em;
        $dispatcher->addListener(ApiKeyCreated::class, static function (ApiKeyCreated $event) use (&$seen, $em): void {
            $seen[] = [$event->key->id, $em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_keys WHERE id = :id', ['id' => (string) $event->key->id])];
        });

        $view = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::Never);

        self::assertSame([[$view->key->id, 1]], $seen);
    }

    public function test_revoke_stamps_the_key_and_records_who_revoked_it(): void
    {
        $key = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::Never)->key;
        $this->audit->forget();

        $this->revoke($key, $this->owner);

        self::assertEquals(new \DateTimeImmutable(self::NOW), $key->revokedAt);
        $event = $this->audit->record('oauth.api_key_revoked');
        self::assertSame((string) $key->id, $event->subject?->id);
        self::assertSame('owner', $event->context['revokedBy'] ?? null);
    }

    public function test_revoke_by_an_admin_is_recorded_as_an_admin_revoke(): void
    {
        $key = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::Never)->key;
        $admin = new User('Admin', 'api-key-admin@example.com', 'x');
        $admin->roles = ['ROLE_ADMIN'];
        $this->em->persist($admin);
        $this->em->flush();
        $this->audit->forget();

        $this->revoke($key, $admin);

        self::assertNotNull($key->revokedAt);
        self::assertSame('admin', $this->audit->record('oauth.api_key_revoked')->context['revokedBy'] ?? null);
    }

    public function test_revoke_refuses_a_key_that_is_already_revoked(): void
    {
        $key = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::Never)->key;
        $this->revoke($key, $this->owner);
        $first = $key->revokedAt;
        $this->audit->forget();

        try {
            $this->revoke($key, $this->owner);
            self::fail('a second revoke was accepted');
        } catch (DomainErrors $e) {
            self::assertSame(['key' => 'oauth.api_key.error.already_revoked'], $e->errors);
        }
        self::assertSame($first, $key->revokedAt);
        self::assertSame([], $this->audit->operations());
    }

    public function test_remove_deletes_a_revoked_key(): void
    {
        $key = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::Never)->key;
        $id = (string) $key->id;
        $this->revoke($key, $this->owner);

        $this->remove($key);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_keys WHERE id = :id', ['id' => $id]));
    }

    public function test_remove_deletes_an_expired_key(): void
    {
        $key = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::ThirtyDays)->key;
        $id = (string) $key->id;
        $this->clock->modify('2027-01-01 00:00:00');

        $this->remove($key);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_keys WHERE id = :id', ['id' => $id]));
    }

    public function test_remove_refuses_a_key_that_still_works(): void
    {
        $key = $this->createFor($this->owner, 'CI server', ApiKeyExpiry::ThirtyDays)->key;
        $id = (string) $key->id;

        try {
            $this->remove($key);
            self::fail('an active key was removed');
        } catch (DomainErrors $e) {
            self::assertSame(['key' => 'oauth.api_key.error.remove_active'], $e->errors);
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM api_keys WHERE id = :id', ['id' => $id]));
    }

    public function test_list_returns_the_keys_of_one_owner_newest_first(): void
    {
        $older = $this->createFor($this->owner, 'older', ApiKeyExpiry::Never)->key;
        $this->clock->modify('2026-11-02 12:00:00');
        $newer = $this->createFor($this->owner, 'newer', ApiKeyExpiry::Never)->key;
        $stranger = new OAuthScenario(static::getContainer())->createUser('api-key-stranger@example.com');
        $this->createFor($stranger, 'theirs', ApiKeyExpiry::Never);

        $apiKeys = static::getContainer()->get(ApiKeyRepository::class);
        self::assertInstanceOf(ApiKeyRepository::class, $apiKeys);
        $handler = new ListApiKeysHandler($apiKeys);
        $view = $handler(new ListApiKeysCommand($this->owner));

        self::assertSame([$newer->id?->toRfc4122(), $older->id?->toRfc4122()], array_map(static fn (ApiKey $key): ?string => $key->id?->toRfc4122(), $view->keys));
    }

    private function createFor(User $owner, string $name, ApiKeyExpiry $expiry): CreateApiKeyView
    {
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $handler = new CreateApiKeyHandler($this->em, $this->clock, $dispatcher, $this->audit->auditor);

        return $handler(new CreateApiKeyCommand($owner, $name, $expiry));
    }

    private function revoke(ApiKey $key, User $actor): void
    {
        $handler = new RevokeApiKeyHandler($this->em, $this->clock, $this->audit->auditor);
        $handler(new RevokeApiKeyCommand($key, $actor));
    }

    private function remove(ApiKey $key): void
    {
        $handler = new RemoveApiKeyHandler($this->em, $this->clock);
        $handler(new RemoveApiKeyCommand($key));
    }
}
