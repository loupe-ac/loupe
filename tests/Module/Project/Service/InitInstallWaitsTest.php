<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Project\Service\InitInstallWaits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

final class InitInstallWaitsTest extends TestCase
{
    public function test_a_mark_holds_for_its_session_and_project_only(): void
    {
        $waits = new InitInstallWaits(new ArrayAdapter());
        $session = (string) Uuid::v4();
        $project = (string) Uuid::v4();

        self::assertFalse($waits->isWaiting($session, $project));
        $waits->mark($session, $project);

        self::assertTrue($waits->isWaiting($session, $project));
        self::assertTrue($waits->isWaiting(strtoupper($session), $project));
        self::assertFalse($waits->isWaiting($session, (string) Uuid::v4()));
        self::assertFalse($waits->isWaiting((string) Uuid::v4(), $project));
    }

    public function test_clear_ends_the_mark(): void
    {
        $waits = new InitInstallWaits(new ArrayAdapter());
        $session = (string) Uuid::v4();
        $project = (string) Uuid::v4();
        $waits->mark($session, $project);

        $waits->clear($session);

        self::assertFalse($waits->isWaiting($session, $project));
    }

    public function test_a_value_that_is_no_uuid_is_never_waiting(): void
    {
        $waits = new InitInstallWaits(new ArrayAdapter());
        $project = (string) Uuid::v4();
        $waits->mark('not-a-uuid', $project);

        self::assertFalse($waits->isWaiting(null, $project));
        self::assertFalse($waits->isWaiting('not-a-uuid', $project));
        self::assertFalse($waits->isWaiting('', $project));
    }
}
