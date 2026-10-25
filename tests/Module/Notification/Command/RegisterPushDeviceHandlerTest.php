<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Command;

use App\Exception\DomainErrors;
use App\Module\Notification\Command\RegisterPushDeviceCommand;
use App\Module\Notification\Command\RegisterPushDeviceHandler;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushEndpointPolicy;
use App\Tests\Module\Notification\NotificationScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;

final class RegisterPushDeviceHandlerTest extends KernelTestCase
{
    use NotificationScenario;

    private RecordingAuditor $audit;

    public function test_it_stores_a_new_device_with_the_label_the_user_typed(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-new@example.com');
        $keys = self::subscriptionKeys();

        $device = $this->register($user, $keys, label: '  Kitchen iPad ');

        self::assertNotNull($device->id);
        self::assertSame($user, $device->owner);
        self::assertSame(self::ENDPOINT, $device->endpoint);
        self::assertSame($keys['publicKey'], $device->publicKey);
        self::assertSame($keys['authToken'], $device->authToken);
        self::assertSame('aes128gcm', $device->contentEncoding);
        self::assertSame('Kitchen iPad', $device->label);
        self::assertSame('hash-1', $device->deviceTokenHash);
        $this->audit->record('notification.device_registered');
    }

    public function test_a_blank_label_comes_from_the_user_agent(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-label@example.com');

        $device = $this->register($user, self::subscriptionKeys(), label: '  ', userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');

        self::assertSame('Chrome on macOS', $device->label);
    }

    public function test_a_long_label_is_cut_to_the_column_length(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-long@example.com');

        $device = $this->register($user, self::subscriptionKeys(), label: str_repeat('x', 300));

        self::assertSame(PushDevice::LABEL_MAX_LENGTH, mb_strlen($device->label));
    }

    public function test_it_refuses_when_push_is_unavailable(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-off@example.com');

        $errors = $this->errorsOf(fn () => $this->register($user, self::subscriptionKeys(), availability: self::availability(false)));

        self::assertSame(['endpoint' => 'notification.device.error.unavailable'], $errors);
        self::assertNull(static::getContainer()->get(PushDeviceRepository::class)->findOneByEndpoint(self::ENDPOINT));
    }

    public function test_it_refuses_an_endpoint_outside_the_allow_list(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-policy@example.com');

        $errors = $this->errorsOf(fn () => $this->register($user, self::subscriptionKeys(), endpoint: 'https://10.0.0.5/admin'));

        self::assertSame(['endpoint' => 'notification.device.error.endpoint_refused'], $errors);
    }

    public function test_it_refuses_missing_fields(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-missing@example.com');

        $errors = $this->errorsOf(fn () => $this->register($user, ['publicKey' => '', 'authToken' => ''], endpoint: ''));

        self::assertSame([
            'endpoint' => 'notification.device.error.required',
            'publicKey' => 'notification.device.error.required',
            'authToken' => 'notification.device.error.required',
        ], $errors);
    }

    public function test_it_refuses_keys_that_a_browser_would_never_send(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-keys@example.com');

        $errors = $this->errorsOf(fn () => $this->register($user, ['publicKey' => 'not-a-key', 'authToken' => 'short']));

        self::assertSame([
            'publicKey' => 'notification.device.error.key_invalid',
            'authToken' => 'notification.device.error.key_invalid',
        ], $errors);
    }

    public function test_it_refuses_an_unknown_content_encoding(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-encoding@example.com');

        $errors = $this->errorsOf(fn () => $this->register($user, self::subscriptionKeys(), encoding: 'aes256'));

        self::assertSame(['contentEncoding' => 'notification.device.error.encoding_invalid'], $errors);
    }

    public function test_it_refuses_an_endpoint_that_belongs_to_another_user(): void
    {
        $container = static::getContainer();
        $other = $this->makeUser($container, 'register-other@example.com');
        $user = $this->makeUser($container, 'register-thief@example.com');
        $theirs = $this->makeDevice($container, $other);

        $errors = $this->errorsOf(fn () => $this->register($user, self::subscriptionKeys()));

        self::assertSame(['endpoint' => 'notification.device.error.endpoint_taken'], $errors);
        $em = $container->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(PushDevice::class, $theirs->id);
        self::assertNotNull($stored);
        self::assertSame($other->id?->toRfc4122(), $stored->owner->id?->toRfc4122());
    }

    public function test_the_same_endpoint_of_the_same_user_updates_the_row(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'register-same-endpoint@example.com');
        $existing = $this->makeDevice($container, $user, hash: 'old-hash');
        $existing->stoppedAt = new \DateTimeImmutable('2026-01-01');
        $keys = self::subscriptionKeys();

        $device = $this->register($user, $keys, label: 'Renamed');

        self::assertSame($existing->id, $device->id);
        self::assertSame($keys['publicKey'], $device->publicKey);
        self::assertSame('hash-1', $device->deviceTokenHash);
        self::assertSame('Renamed', $device->label);
        self::assertNull($device->stoppedAt);
        self::assertSame(1, $container->get(PushDeviceRepository::class)->count(['owner' => $user]));
    }

    public function test_a_cookie_hash_that_matches_a_stopped_row_reuses_it(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'register-resubscribe@example.com');
        $stopped = $this->makeDevice($container, $user, endpoint: 'https://web.push.apple.com/OLD', hash: 'hash-1');
        $stopped->stoppedAt = new \DateTimeImmutable('2026-01-01');
        $stopped->warningDismissedAt = new \DateTimeImmutable('2026-01-02');
        $keys = self::subscriptionKeys();

        $device = $this->register($user, $keys, label: '');

        self::assertSame($stopped->id, $device->id);
        self::assertSame(self::ENDPOINT, $device->endpoint);
        self::assertSame($keys['authToken'], $device->authToken);
        self::assertNull($device->stoppedAt);
        self::assertNull($device->warningDismissedAt);
        self::assertSame('Chrome on macOS', $device->label, 'a blank label keeps the old one');
        self::assertSame(1, $container->get(PushDeviceRepository::class)->count(['owner' => $user]));
    }

    public function test_a_cookie_hash_of_another_user_is_not_reused(): void
    {
        $container = static::getContainer();
        $other = $this->makeUser($container, 'register-shared-browser-a@example.com');
        $user = $this->makeUser($container, 'register-shared-browser-b@example.com');
        $theirs = $this->makeDevice($container, $other, endpoint: 'https://web.push.apple.com/THEIRS', hash: 'hash-1');

        $device = $this->register($user, self::subscriptionKeys());

        self::assertNotSame($theirs->id, $device->id);
        self::assertSame($user, $device->owner);
        self::assertSame(1, $container->get(PushDeviceRepository::class)->count(['owner' => $other]));
    }

    public function test_when_the_cookie_row_and_the_endpoint_row_differ_one_row_survives(): void
    {
        $container = static::getContainer();
        $user = $this->makeUser($container, 'register-two-rows@example.com');
        $this->makeDevice($container, $user, endpoint: self::ENDPOINT, hash: 'stale-hash');
        $cookieRow = $this->makeDevice($container, $user, endpoint: 'https://web.push.apple.com/OTHER', hash: 'hash-1');

        $device = $this->register($user, self::subscriptionKeys());

        self::assertSame($cookieRow->id, $device->id);
        self::assertSame(self::ENDPOINT, $device->endpoint);
        self::assertSame(1, $container->get(PushDeviceRepository::class)->count(['owner' => $user]));
    }

    public function test_a_second_request_that_loses_the_race_for_an_endpoint_gets_a_form_error(): void
    {
        $user = $this->makeUser(static::getContainer(), 'register-race@example.com');
        $keys = self::subscriptionKeys();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new UniqueConstraintViolationException(
            $this->createStub(\Doctrine\DBAL\Driver\Exception::class),
            null,
        ));

        $handler = new RegisterPushDeviceHandler(
            static::getContainer()->get(PushDeviceRepository::class),
            self::availability(),
            new PushEndpointPolicy(),
            $em,
            new RecordingAuditor(static::getContainer()->get(AuditActorProviderInterface::class))->auditor,
        );

        $errors = $this->errorsOf(fn () => $handler(new RegisterPushDeviceCommand(
            user: $user,
            endpoint: self::ENDPOINT,
            publicKey: $keys['publicKey'],
            authToken: $keys['authToken'],
            contentEncoding: 'aes128gcm',
            label: null,
            userAgent: null,
            deviceTokenHash: 'hash-1',
        )));

        self::assertSame(['endpoint' => 'notification.device.error.endpoint_taken'], $errors);
    }

    /** @param array<string, string> $keys */
    private function register(
        \App\Module\Account\Entity\User $user,
        array $keys,
        string $endpoint = self::ENDPOINT,
        string $encoding = 'aes128gcm',
        ?string $label = null,
        ?string $userAgent = null,
        ?PushAvailability $availability = null,
    ): PushDevice {
        $container = static::getContainer();
        $actors = $container->get(AuditActorProviderInterface::class);
        $this->audit = new RecordingAuditor($actors);

        $handler = new RegisterPushDeviceHandler(
            $container->get(PushDeviceRepository::class),
            $availability ?? self::availability(),
            new PushEndpointPolicy(),
            $container->get(EntityManagerInterface::class),
            $this->audit->auditor,
        );

        return $handler(new RegisterPushDeviceCommand(
            user: $user,
            endpoint: $endpoint,
            publicKey: $keys['publicKey'],
            authToken: $keys['authToken'],
            contentEncoding: $encoding,
            label: $label,
            userAgent: $userAgent,
            deviceTokenHash: 'hash-1',
        ));
    }

    /** @return array<string, string> */
    private function errorsOf(callable $call): array
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            return $e->errors;
        }

        self::fail('Expected DomainErrors.');
    }
}
