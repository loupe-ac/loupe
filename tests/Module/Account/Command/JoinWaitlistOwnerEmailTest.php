<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\JoinWaitlistCommand;
use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistJoinSource;
use App\Module\Account\Entity\WaitlistPick;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\WaitlistNotificationRecipients;
use App\Module\Account\Service\WaitlistOwnerNotifier;
use App\Tests\Support\InstalledInstance;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class JoinWaitlistOwnerEmailTest extends KernelTestCase
{
    public function test_a_form_join_emails_each_listed_address_once(): void
    {
        $this->setRecipients('owner@example.com, second@example.org');
        $waitingBefore = self::getContainer()->get(WaitlistEntryRepository::class)->countWaiting();

        $this->join(new JoinWaitlistCommand('visitor@example.com', [WaitlistPick::GitLab, WaitlistPick::Cursor], 'Zed'));

        $this->assertQueuedEmailCount(2);
        $first = $this->email(0);
        $second = $this->email(1);
        self::assertSame('owner@example.com', $first->getTo()[0]->getAddress());
        self::assertSame('second@example.org', $second->getTo()[0]->getAddress());
        $html = $first->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('visitor@example.com joined the waitlist with the waitlist form.', $html);
        self::assertStringContainsString('Picks: GitLab, Cursor.', $html);
        self::assertStringContainsString('Other: Zed', $html);
        self::assertStringContainsString(0 === $waitingBefore ? 'One person is waiting now.' : \sprintf('%d people are waiting now.', $waitingBefore + 1), $html);
        self::assertStringContainsString('/admin/waitlist"', $html);
        self::assertStringNotContainsString('second@example.org', $html);
    }

    public function test_a_social_login_join_names_its_source(): void
    {
        $this->setRecipients('owner@example.com');

        $this->join(new JoinWaitlistCommand('visitor@example.com', source: WaitlistJoinSource::SocialLogin));

        $this->assertQueuedEmailCount(1);
        $html = $this->email(0)->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('visitor@example.com joined the waitlist through a social login while sign-up was full.', $html);
        self::assertStringNotContainsString('Picks:', $html);
    }

    public function test_a_social_login_at_the_cap_emails_the_owner_through_the_waitlist(): void
    {
        $this->setRecipients('owner@example.com');
        InstalledInstance::ensure(self::getContainer());
        $this->em()->persist(new FeatureFlag(RegistrationGate::CAP_FLAG, FeatureFlagType::Int, 1));
        $this->em()->flush();
        $handler = self::getContainer()->get(ResolveSocialLoginHandler::class);
        self::assertInstanceOf(ResolveSocialLoginHandler::class, $handler);

        $outcome = $handler(new ResolveSocialLoginCommand(
            new SocialProfile(SocialProvider::Github, 'gh-full', 'full@example.com', 'Full', true),
        ));

        self::assertTrue($outcome->waitlisted);
        $this->assertQueuedEmailCount(1);
        $html = $this->email(0)->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('full@example.com joined the waitlist through a social login while sign-up was full.', $html);
    }

    public function test_a_rejoin_by_a_disabled_account_says_so(): void
    {
        $this->setRecipients('owner@example.com');
        $em = $this->em();
        $entry = new WaitlistEntry('returning@example.com');
        $entry->markConverted();
        $em->persist($entry);
        $user = new User(fullName: 'Returning', email: 'returning@example.com', password: 'x');
        $user->disabledAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $this->join(new JoinWaitlistCommand('returning@example.com'));

        $this->assertQueuedEmailCount(1);
        $html = $this->email(0)->getHtmlBody();
        self::assertIsString($html);
        self::assertStringContainsString('returning@example.com joined the waitlist again after their account was disabled.', $html);
    }

    public function test_a_picks_update_sends_nothing(): void
    {
        $this->setRecipients('owner@example.com');
        $this->em()->persist(new WaitlistEntry('waiting@example.com'));
        $this->em()->flush();

        $this->join(new JoinWaitlistCommand('waiting@example.com', [WaitlistPick::Pi]));

        self::assertSame(['pi'], self::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('waiting@example.com')?->picks);
        $this->assertQueuedEmailCount(0);
    }

    public function test_a_plain_duplicate_sends_nothing(): void
    {
        $this->setRecipients('owner@example.com');
        $this->em()->persist(new WaitlistEntry('waiting@example.com'));
        $this->em()->flush();

        $this->join(new JoinWaitlistCommand('waiting@example.com'));

        $this->assertQueuedEmailCount(0);
    }

    public function test_an_address_with_an_enabled_account_sends_nothing(): void
    {
        $this->setRecipients('owner@example.com');
        $this->em()->persist(new User(fullName: 'Member', email: 'member@example.com', password: 'x'));
        $this->em()->flush();

        $this->join(new JoinWaitlistCommand('member@example.com'));

        $this->assertQueuedEmailCount(0);
    }

    public function test_an_empty_list_sends_nothing_and_still_joins(): void
    {
        $this->setRecipients('');

        $this->join(new JoinWaitlistCommand('visitor@example.com'));

        $this->assertQueuedEmailCount(0);
        self::assertNotNull(self::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('visitor@example.com'));
    }

    public function test_a_lost_uniqueness_race_sends_nothing(): void
    {
        $this->setRecipients('owner@example.com');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush')->willThrowException(new UniqueConstraintViolationException(
            PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint')),
            null,
        ));
        $repo = $this->createStub(WaitlistEntryRepository::class);
        $users = $this->createStub(UserRepository::class);
        $auditor = self::getContainer()->get(Auditor::class);
        self::assertInstanceOf(Auditor::class, $auditor);
        $notifier = self::getContainer()->get(WaitlistOwnerNotifier::class);
        self::assertInstanceOf(WaitlistOwnerNotifier::class, $notifier);

        new JoinWaitlistHandler($repo, $users, $em, $auditor, $notifier)(new JoinWaitlistCommand('racer@example.com'));

        $this->assertQueuedEmailCount(0);
    }

    private function join(JoinWaitlistCommand $command): void
    {
        $handler = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $handler($command);
    }

    private function email(int $index): TemplatedEmail
    {
        $message = $this->getMailerMessage($index);
        self::assertInstanceOf(TemplatedEmail::class, $message);

        return $message;
    }

    private function setRecipients(string $value): void
    {
        $flag = self::getContainer()->get(\Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository::class)->findOneBy(['name' => WaitlistNotificationRecipients::FLAG]);
        self::assertInstanceOf(FeatureFlag::class, $flag);
        $flag->value = $value;
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
