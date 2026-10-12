<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command\Console;

use App\Module\Account\Repository\UserRepository;
use App\Module\OAuth\Command\Console\MintPreviewTokenConsoleCommand;
use App\Module\OAuth\Command\MintPreviewTokenHandler;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use App\Tests\Support\OAuthScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MintPreviewTokenConsoleCommandTest extends KernelTestCase
{
    use PreviewTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario('dev-preview@example.com');
    }

    public function test_it_prints_one_token_and_records_the_label(): void
    {
        $tester = $this->tester();
        $tester->execute(['--project' => (string) $this->project->id, '--label' => 'card-1', '--email' => 'dev-preview@example.com']);

        $tester->assertCommandIsSuccessful();
        $token = trim($tester->getDisplay());
        self::assertStringNotContainsString("\n", $token);
        $id = OAuthScenario::claimsOf($token)['jti'] ?? null;
        self::assertIsString($id);
        self::assertSame('card-1', $this->connection->fetchOne('SELECT label FROM oauth_preview_tokens WHERE access_token_id = :id', ['id' => $id]));
    }

    public function test_it_names_the_missing_account(): void
    {
        $tester = $this->tester();
        $tester->execute(['--project' => (string) $this->project->id, '--label' => 'card-1', '--email' => 'nobody@example.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No account', $tester->getDisplay());
    }

    public function test_it_refuses_a_project_the_account_does_not_own(): void
    {
        $tester = $this->tester();
        $tester->execute(['--project' => 'no-such-project', '--label' => 'card-1', '--email' => 'dev-preview@example.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
    }

    public function test_it_requires_a_project_and_a_label(): void
    {
        $tester = $this->tester();
        $tester->execute(['--label' => 'card-1']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function tester(): CommandTester
    {
        // The command exists in the dev environment only, so build it by hand.
        return new CommandTester(new MintPreviewTokenConsoleCommand(
            static::getContainer()->get(UserRepository::class),
            static::getContainer()->get(MintPreviewTokenHandler::class),
        ));
    }
}
