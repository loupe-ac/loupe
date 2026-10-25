<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Messenger;

use App\Module\Account\Entity\User;
use App\Module\Notification\Entity\PushDevice;
use App\Module\Notification\Messenger\DeliverWebPush;
use App\Module\Notification\Messenger\DeliverWebPushHandler;
use App\Module\Notification\Repository\PushDeviceRepository;
use App\Module\Notification\Service\PushAvailability;
use App\Module\Notification\Service\PushEndpointPolicy;
use App\Tests\Module\Notification\RecordedDeviceSignals;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class DeliverWebPushHandlerTest extends TestCase
{
    private const string ENDPOINT = 'https://web.push.apple.com/QGxyz';

    /** @var list<array{url: string, headers: array<string, string>}> */
    private array $sent = [];

    private \DateTimeImmutable $now;

    private RecordedDeviceSignals $signals;

    public function test_it_sends_a_declarative_payload_with_no_topic(): void
    {
        $device = $this->device();

        $this->handle($device, new MockResponse('', ['http_code' => 201]));

        self::assertCount(1, $this->sent);
        $request = $this->sent[0];
        self::assertSame(self::ENDPOINT, $request['url']);
        self::assertSame('application/notification+json', $request['headers']['content-type']);
        self::assertArrayNotHasKey('topic', $request['headers']);
        self::assertSame('high', $request['headers']['urgency']);
        self::assertSame('604800', $request['headers']['ttl']);
        self::assertSame('aes128gcm', $request['headers']['content-encoding']);
        self::assertStringStartsWith('vapid t=', $request['headers']['authorization']);
    }

    public function test_the_urgency_and_the_ttl_come_from_the_message(): void
    {
        $device = $this->device();

        $this->handle($device, new MockResponse('', ['http_code' => 201]), urgency: 'normal', ttl: 60);

        self::assertSame('normal', $this->sent[0]['headers']['urgency']);
        self::assertSame('60', $this->sent[0]['headers']['ttl']);
    }

    public function test_a_success_sets_the_delivery_time(): void
    {
        $device = $this->device();

        $this->handle($device, new MockResponse('', ['http_code' => 201]));

        self::assertEquals($this->now, $device->lastDeliveredAt);
        self::assertNull($device->stoppedAt);
        $this->signals->assertNothingSignalled();
    }

    public function test_a_404_or_a_410_stops_the_device(): void
    {
        foreach ([404, 410] as $status) {
            $device = $this->device();

            $this->handle($device, new MockResponse('', ['http_code' => $status]));

            self::assertEquals($this->now, $device->stoppedAt, (string) $status);
            self::assertNull($device->lastDeliveredAt);
            $this->signals->assertSignalled($device->owner->id);
        }
    }

    public function test_a_429_and_a_5xx_ask_for_a_retry(): void
    {
        foreach ([429, 500, 502, 503] as $status) {
            $device = $this->device();

            try {
                $this->handle($device, new MockResponse('', ['http_code' => $status]));
                self::fail('Expected a retry for '.$status);
            } catch (RecoverableMessageHandlingException) {
                self::assertNull($device->stoppedAt);
                self::assertNull($device->lastDeliveredAt);
                $this->signals->assertNothingSignalled();
            }
        }
    }

    public function test_a_network_error_asks_for_a_retry(): void
    {
        $device = $this->device();

        $this->expectException(RecoverableMessageHandlingException::class);

        $this->handle($device, new MockResponse('', ['error' => 'connection refused']));
    }

    public function test_the_retry_keeps_the_budget_of_the_transport(): void
    {
        $device = $this->device();

        try {
            $this->handle($device, new MockResponse('', ['http_code' => 503]));
            self::fail('Expected a retry');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertFalse($e->forceRetry());
        }
    }

    public function test_another_4xx_is_dropped(): void
    {
        foreach ([400, 401, 403, 413] as $status) {
            $device = $this->device();

            $this->handle($device, new MockResponse('', ['http_code' => $status]));

            self::assertCount(1, $this->sent);
            self::assertNull($device->stoppedAt, (string) $status);
            self::assertNull($device->lastDeliveredAt, (string) $status);
            $this->sent = [];
        }
    }

    public function test_a_redirect_is_not_followed_and_not_counted_as_delivered(): void
    {
        $device = $this->device();

        $this->handle($device, new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://10.0.0.5/']]));

        self::assertCount(1, $this->sent);
        self::assertNull($device->lastDeliveredAt);
    }

    public function test_a_stopped_device_gets_nothing(): void
    {
        $device = $this->device();
        $device->stoppedAt = new \DateTimeImmutable('2026-01-01');

        $this->handle($device, new MockResponse('', ['http_code' => 201]));

        self::assertSame([], $this->sent);
    }

    public function test_an_unknown_device_is_skipped(): void
    {
        $this->handle(null, new MockResponse('', ['http_code' => 201]));

        self::assertSame([], $this->sent);
    }

    public function test_it_sends_nothing_when_push_is_unavailable(): void
    {
        $device = $this->device();

        $this->handle($device, new MockResponse('', ['http_code' => 201]), flag: false);

        self::assertSame([], $this->sent);
        self::assertNull($device->lastDeliveredAt);
    }

    public function test_it_sends_nothing_to_an_endpoint_outside_the_allow_list(): void
    {
        $device = $this->device('https://10.0.0.5/admin');

        $this->handle($device, new MockResponse('', ['http_code' => 201]));

        self::assertSame([], $this->sent);
        self::assertNull($device->lastDeliveredAt);
    }

    public function test_a_payload_that_is_too_large_is_dropped(): void
    {
        $device = $this->device();

        $this->handle($device, new MockResponse('', ['http_code' => 201]), payload: str_repeat('a', 5000));

        self::assertSame([], $this->sent);
        self::assertNull($device->lastDeliveredAt);
    }

    public function test_a_device_with_an_unusable_key_is_dropped(): void
    {
        $device = $this->device();
        $device->publicKey = 'not-a-key';

        $this->handle($device, new MockResponse('', ['http_code' => 201]));

        self::assertSame([], $this->sent);
        self::assertNull($device->lastDeliveredAt);
    }

    private function handle(?PushDevice $device, MockResponse $response, bool $flag = true, string $urgency = 'high', int $ttl = 604800, string $payload = '{"web_push":8030}'): void
    {
        $this->now = new \DateTimeImmutable('2026-10-24 12:00:00');
        $this->signals = new RecordedDeviceSignals();
        $keys = VAPID::createVapidKeys();
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('isEnabled')->willReturn($flag);
        $devices = $this->createStub(PushDeviceRepository::class);
        $devices->method('find')->willReturn($device);
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $headers = [];
            foreach ($options['headers'] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->sent[] = ['url' => $url, 'headers' => $headers];
            self::assertSame('POST', $method);

            return $response;
        });

        $handler = new DeliverWebPushHandler(
            $devices,
            new PushAvailability($flags, $keys['publicKey'], $keys['privateKey']),
            new PushEndpointPolicy(),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            new MockClock($this->now),
            $this->signals->publisher,
            $client,
            'https://loupe.example',
        );

        $handler(new DeliverWebPush((string) ($device->id ?? Uuid::v7()), $payload, $urgency, $ttl));
    }

    private function device(string $endpoint = self::ENDPOINT): PushDevice
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT);

        $owner = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
        new \ReflectionProperty(User::class, 'id')->setValue($owner, Uuid::v7());
        $device = new PushDevice(
            $owner,
            $endpoint,
            rtrim(strtr(base64_encode($point), '+/', '-_'), '='),
            rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            'aes128gcm',
            'Safari on iPhone',
            hash('sha256', 'cookie'),
        );
        $reflection = new \ReflectionProperty(PushDevice::class, 'id');
        $reflection->setValue($device, Uuid::v7());

        return $device;
    }
}
