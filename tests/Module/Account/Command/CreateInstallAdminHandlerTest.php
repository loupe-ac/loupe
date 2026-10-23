<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\CreateInstallAdminCommand;
use App\Module\Account\Command\CreateInstallAdminHandler;
use App\Module\Account\Service\WaitlistNotificationRecipients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class CreateInstallAdminHandlerTest extends KernelTestCase
{
    public function test_creates_unverified_admin_with_hashed_password(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);

        $user = $handler(new CreateInstallAdminCommand(
            email: 'admin@example.com',
            fullName: 'Ada Lovelace',
            plainPassword: 'a-strong-password',
        ));

        self::assertSame(['ROLE_ADMIN'], $user->roles);
        self::assertSame('Ada Lovelace', $user->fullName);
        self::assertNull($user->emailVerifiedAt);
        self::assertNotSame('a-strong-password', $user->password);
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function test_sends_verification_email(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);
        $handler(new CreateInstallAdminCommand('admin@example.com', 'Ada Lovelace', 'a-strong-password'));

        $this->assertQueuedEmailCount(1);
    }

    public function test_throws_once_any_user_exists(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);
        $handler(new CreateInstallAdminCommand('admin@example.com', 'Ada Lovelace', 'a-strong-password'));

        $this->expectException(DomainErrors::class);
        $handler(new CreateInstallAdminCommand('admin2@example.com', 'Grace Hopper', 'a-strong-password'));
    }

    public function test_an_empty_recipient_list_gets_the_first_admin(): void
    {
        $this->setRecipientFlag('');

        self::getContainer()->get(CreateInstallAdminHandler::class)(
            new CreateInstallAdminCommand('First@Example.com', 'Ada Lovelace', 'a-strong-password'),
        );

        self::assertSame('first@example.com', $this->recipientFlag()->value);
    }

    public function test_a_missing_recipient_flag_is_created_with_the_first_admin(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $existing = $this->recipientFlag();
        $em->remove($existing);
        $em->flush();

        self::getContainer()->get(CreateInstallAdminHandler::class)(
            new CreateInstallAdminCommand('first@example.com', 'Ada Lovelace', 'a-strong-password'),
        );

        $created = $this->recipientFlag();
        self::assertSame('first@example.com', $created->value);
        self::assertSame(FeatureFlagType::String, $created->type);
    }

    public function test_a_filled_recipient_list_is_left_alone(): void
    {
        $this->setRecipientFlag('owner@example.com');

        self::getContainer()->get(CreateInstallAdminHandler::class)(
            new CreateInstallAdminCommand('first@example.com', 'Ada Lovelace', 'a-strong-password'),
        );

        self::assertSame('owner@example.com', $this->recipientFlag()->value);
    }

    public function test_a_refused_install_leaves_the_recipient_list_alone(): void
    {
        $handler = self::getContainer()->get(CreateInstallAdminHandler::class);
        $handler(new CreateInstallAdminCommand('first@example.com', 'Ada Lovelace', 'a-strong-password'));
        $this->setRecipientFlag('');

        try {
            $handler(new CreateInstallAdminCommand('second@example.com', 'Grace Hopper', 'a-strong-password'));
            self::fail('The second install must be refused.');
        } catch (DomainErrors) {
        }

        self::assertSame('', $this->recipientFlag()->value);
    }

    private function setRecipientFlag(string $value): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $flag = $this->recipientFlag();
        $flag->value = $value;
        $em->flush();
    }

    private function recipientFlag(): FeatureFlag
    {
        $flag = self::getContainer()->get(FeatureFlagRepository::class)->findOneBy(['name' => WaitlistNotificationRecipients::FLAG]);
        self::assertInstanceOf(FeatureFlag::class, $flag);

        return $flag;
    }
}
