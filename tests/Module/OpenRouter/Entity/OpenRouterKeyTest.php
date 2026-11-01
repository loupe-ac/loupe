<?php

declare(strict_types=1);

namespace App\Tests\Module\OpenRouter\Entity;

use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Tests\Module\OpenRouter\OpenRouterScenario;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenRouterKeyTest extends KernelTestCase
{
    use OpenRouterScenario;

    public function test_the_secret_is_encrypted_at_rest_and_read_back(): void
    {
        self::bootKernel();
        $key = $this->makeKey(self::getContainer(), $this->makeUser(self::getContainer(), 'at-rest'), 'sk-or-v1-plain-text-secret-a3f9');

        $raw = $this->em()->getConnection()->fetchAssociative('SELECT secret, last_four, state FROM openrouter_keys WHERE id = :id', ['id' => (string) $key->id]);
        self::assertIsArray($raw);
        self::assertStringNotContainsString('plain-text-secret', (string) $raw['secret']);
        self::assertSame('a3f9', $raw['last_four']);
        self::assertSame('working', $raw['state']);
        $this->em()->clear();
        $reloaded = $this->em()->find(OpenRouterKey::class, $key->id);
        self::assertNotNull($reloaded);
        self::assertSame('sk-or-v1-plain-text-secret-a3f9', $reloaded->secret);
        self::assertSame(OpenRouterKeyState::Working, $reloaded->state);
        self::assertNull($reloaded->refusedSince);
        self::assertNull($reloaded->lastReadAt);
    }

    public function test_the_last_four_characters_come_from_the_secret(): void
    {
        self::assertSame('a3f9', OpenRouterKey::lastFourOf('sk-or-v1-abcdefa3f9'));
        self::assertSame('ab', OpenRouterKey::lastFourOf('ab'));
    }

    public function test_a_key_needs_exactly_one_owner(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'xor');
        $project = $this->makeProject(self::getContainer(), $user);

        try {
            new OpenRouterKey('secret', user: $user, project: $project);
            self::fail('A key with two owners must be refused.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        new OpenRouterKey('secret');
    }

    public function test_the_database_refuses_a_row_with_two_owners(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'check-two');
        $project = $this->makeProject(self::getContainer(), $user);

        $this->expectInsertRefused((string) $user->id, (string) $project->id);
    }

    public function test_the_database_refuses_a_row_with_no_owner(): void
    {
        self::bootKernel();

        $this->expectInsertRefused(null, null);
    }

    private function expectInsertRefused(?string $userId, ?string $projectId): void
    {
        try {
            $this->em()->getConnection()->executeStatement(
                'INSERT INTO openrouter_keys (id, user_id, project_id, secret, last_four, state, created_at) VALUES (gen_random_uuid(), :user, :project, \'x\', \'abcd\', \'working\', now())',
                ['user' => $userId, 'project' => $projectId],
            );
            self::fail('The insert must be refused.');
        } catch (DriverException $e) {
            self::assertStringContainsString('openrouter_keys_one_owner', $e->getMessage());
        }
    }

    public function test_a_user_has_at_most_one_key(): void
    {
        self::bootKernel();
        $user = $this->makeUser(self::getContainer(), 'unique-user');
        $this->makeKey(self::getContainer(), $user);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeKey(self::getContainer(), $user);
    }

    public function test_a_project_has_at_most_one_key(): void
    {
        self::bootKernel();
        $project = $this->makeProject(self::getContainer(), $this->makeUser(self::getContainer(), 'unique-project'));
        $this->makeKey(self::getContainer(), $project);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeKey(self::getContainer(), $project);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
