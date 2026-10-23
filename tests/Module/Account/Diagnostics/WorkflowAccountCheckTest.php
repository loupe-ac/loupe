<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Diagnostics;

use App\Module\Account\Diagnostics\WorkflowAccountCheck;
use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class WorkflowAccountCheckTest extends KernelTestCase
{
    private Connection $connection;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    public function test_the_workflow_account_is_reported_present_when_the_row_exists(): void
    {
        $diagnostic = new WorkflowAccountCheck($this->connection, new NullLogger())();

        self::assertSame('workflow_account', $diagnostic->key);
        self::assertSame('messages', $diagnostic->domain);
        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
    }

    public function test_a_missing_workflow_account_is_a_failure(): void
    {
        $this->connection->delete('users', ['id' => User::WORKFLOW_ID]);

        $diagnostic = new WorkflowAccountCheck($this->connection, new NullLogger())();

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
    }
}
