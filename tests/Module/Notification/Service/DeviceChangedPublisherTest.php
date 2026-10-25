<?php

declare(strict_types=1);

namespace App\Tests\Module\Notification\Service;

use App\Module\Account\Entity\User;
use App\Tests\Module\Notification\RecordedDeviceSignals;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DeviceChangedPublisherTest extends TestCase
{
    public function test_it_queues_the_signal_on_the_notifications_topic_of_the_user(): void
    {
        $signals = new RecordedDeviceSignals();
        $user = $this->user();

        $signals->publisher->changed($user);

        $signals->assertNothingSentYet();
        $signals->assertSignalled($user->id);
    }

    public function test_it_reads_the_user_id_when_called(): void
    {
        $signals = new RecordedDeviceSignals();
        $user = $this->user();
        $original = $user->id;

        $signals->publisher->changed($user);
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v7());

        self::assertNotEquals($original, $user->id);
        $signals->assertSignalled($original);
    }

    public function test_a_user_without_an_id_is_a_logic_error(): void
    {
        $signals = new RecordedDeviceSignals();

        $this->expectException(\LogicException::class);

        $signals->publisher->changed(new User(fullName: 'No Id', email: 'no-id@example.com', password: 'x'));
    }

    private function user(): User
    {
        $user = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v7());

        return $user;
    }
}
