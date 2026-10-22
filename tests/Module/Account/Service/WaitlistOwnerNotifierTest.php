<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Entity\WaitlistJoinSource;
use App\Module\Account\Entity\WaitlistPick;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistNotificationRecipients;
use App\Module\Account\Service\WaitlistOwnerNotifier;
use App\Routing\PinnedUrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class WaitlistOwnerNotifierTest extends TestCase
{
    /** @var list<TemplatedEmail> */
    private array $sent = [];

    /** @var list<array{string, array<string, mixed>}> */
    private array $warnings = [];

    public function test_a_form_join_sends_one_email_to_each_valid_address(): void
    {
        $entry = new WaitlistEntry('visitor@example.com');
        $entry->mergePicks([WaitlistPick::GitLab, WaitlistPick::Cursor], 'Zed');

        $this->notifier(flag: 'ana@example.com, bo@example, cy@example.org', waiting: 7)
            ->joined($entry, WaitlistJoinSource::Form, rejoined: false);

        self::assertCount(2, $this->sent);
        self::assertSame(['ana@example.com'], $this->recipients($this->sent[0]));
        self::assertSame(['cy@example.org'], $this->recipients($this->sent[1]));

        $email = $this->sent[0];
        self::assertSame('@Account/email/waitlist_owner_joined.html.twig', $email->getHtmlTemplate());
        self::assertSame('account.email.waitlist_owner.joined.subject', $email->getSubject());
        self::assertSame([
            'visitorEmail' => 'visitor@example.com',
            'how' => 'form',
            'picks' => ['gitlab', 'cursor'],
            'other' => 'Zed',
            'waitingCount' => 7,
            'adminUrl' => 'https://example.com/app_admin_waitlist_list',
        ], $email->getContext());
        self::assertSame('en', $email->getLocale());
    }

    public function test_a_social_login_join_names_its_source(): void
    {
        $this->notifier(flag: 'ana@example.com')
            ->joined(new WaitlistEntry('visitor@example.com'), WaitlistJoinSource::SocialLogin, rejoined: false);

        self::assertCount(1, $this->sent);
        self::assertSame('social-login', $this->sent[0]->getContext()['how']);
        self::assertSame([], $this->sent[0]->getContext()['picks']);
        self::assertNull($this->sent[0]->getContext()['other']);
    }

    public function test_a_rejoin_says_so_whatever_the_source(): void
    {
        $this->notifier(flag: 'ana@example.com')
            ->joined(new WaitlistEntry('visitor@example.com'), WaitlistJoinSource::SocialLogin, rejoined: true);

        self::assertSame('rejoin', $this->sent[0]->getContext()['how']);
    }

    public function test_an_empty_list_sends_nothing_and_reads_no_count(): void
    {
        $repo = $this->createMock(WaitlistEntryRepository::class);
        $repo->expects($this->never())->method('countWaiting');

        $this->notifier(flag: '', repo: $repo)
            ->joined(new WaitlistEntry('visitor@example.com'), WaitlistJoinSource::Form, rejoined: false);

        self::assertSame([], $this->sent);
    }

    public function test_a_list_of_bad_entries_sends_nothing(): void
    {
        $this->notifier(flag: 'bo@example, carl')
            ->joined(new WaitlistEntry('visitor@example.com'), WaitlistJoinSource::Form, rejoined: false);

        self::assertSame([], $this->sent);
    }

    public function test_a_claim_names_the_account_the_method_and_the_days_to_claim(): void
    {
        $entry = new WaitlistEntry('visitor@example.com');
        $entry->invitedAt = new \DateTimeImmutable('2026-10-20 09:00:00');
        $entry->inviteClaimedAt = new \DateTimeImmutable('2026-10-22 15:00:00');
        $user = new User(fullName: 'Visitor', email: 'visitor@example.com', password: 'x');

        $this->notifier(flag: 'ana@example.com', waiting: 3)->claimed($entry, $user, SocialProvider::Github);

        self::assertCount(1, $this->sent);
        $email = $this->sent[0];
        self::assertSame('@Account/email/waitlist_owner_claimed.html.twig', $email->getHtmlTemplate());
        self::assertSame('account.email.waitlist_owner.claimed.subject', $email->getSubject());
        self::assertSame([
            'visitorEmail' => 'visitor@example.com',
            'method' => 'github',
            'invitedOn' => '2026-10-20',
            'days' => 2,
            'waitingCount' => 3,
            'adminUrl' => 'https://example.com/app_admin_users_detail?id=',
        ], $email->getContext());
    }

    public function test_a_claim_with_no_provider_says_form(): void
    {
        $entry = new WaitlistEntry('visitor@example.com');
        $entry->invitedAt = new \DateTimeImmutable('2026-10-20');
        $entry->inviteClaimedAt = new \DateTimeImmutable('2026-10-20');

        $this->notifier(flag: 'ana@example.com')
            ->claimed($entry, new User(fullName: 'Visitor', email: 'visitor@example.com', password: 'x'), null);

        self::assertSame('form', $this->sent[0]->getContext()['method']);
        self::assertSame(0, $this->sent[0]->getContext()['days']);
    }

    public function test_one_failed_send_does_not_stop_the_others_and_logs_no_address(): void
    {
        $calls = 0;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message) use (&$calls): void {
            ++$calls;
            if (1 === $calls) {
                throw new \RuntimeException('smtp down for ana@example.com');
            }
            \assert($message instanceof TemplatedEmail);
            $this->sent[] = $message;
        });

        $this->notifier(flag: 'ana@example.com, bo@example.org', mailer: $mailer)
            ->joined(new WaitlistEntry('visitor@example.com'), WaitlistJoinSource::Form, rejoined: false);

        self::assertCount(1, $this->sent);
        self::assertSame(['bo@example.org'], $this->recipients($this->sent[0]));
        $this->assertLoggedWithoutAddress();
    }

    public function test_a_failing_count_is_swallowed_and_logged(): void
    {
        $repo = $this->createStub(WaitlistEntryRepository::class);
        $repo->method('countWaiting')->willThrowException(new \RuntimeException('db gone'));

        $this->notifier(flag: 'ana@example.com', repo: $repo)
            ->joined(new WaitlistEntry('visitor@example.com'), WaitlistJoinSource::Form, rejoined: false);

        self::assertSame([], $this->sent);
        $this->assertLoggedWithoutAddress();
    }

    public function test_a_failing_flag_read_is_swallowed_and_logged(): void
    {
        $flags = $this->createStub(FeatureFlagService::class);
        $flags->method('getStringValue')->willThrowException(new \RuntimeException('flag store gone'));

        $this->notifier(flag: '', flags: $flags)
            ->claimed(new WaitlistEntry('visitor@example.com'), new User(fullName: 'Visitor', email: 'visitor@example.com', password: 'x'), null);

        self::assertSame([], $this->sent);
        $this->assertLoggedWithoutAddress();
    }

    private function assertLoggedWithoutAddress(): void
    {
        self::assertCount(1, $this->warnings);
        self::assertSame('account.waitlist_notification_failed', $this->warnings[0][0]);
        self::assertStringNotContainsString('@', json_encode($this->warnings[0][1], \JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function recipients(TemplatedEmail $email): array
    {
        return array_values(array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
    }

    private function notifier(
        string $flag,
        int $waiting = 0,
        ?WaitlistEntryRepository $repo = null,
        ?MailerInterface $mailer = null,
        ?FeatureFlagService $flags = null,
    ): WaitlistOwnerNotifier {
        if (null === $flags) {
            $flags = $this->createStub(FeatureFlagService::class);
            $flags->method('getStringValue')->willReturn($flag);
        }

        if (null === $repo) {
            $repo = $this->createStub(WaitlistEntryRepository::class);
            $repo->method('countWaiting')->willReturn($waiting);
        }

        if (null === $mailer) {
            $mailer = $this->createStub(MailerInterface::class);
            $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
                \assert($message instanceof TemplatedEmail);
                $this->sent[] = $message;
            });
        }

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $router = $this->createStub(RouterInterface::class);
        $router->method('getContext')->willReturn(new RequestContext());
        $router->method('generate')->willReturnCallback(
            static function (string $route, array $parameters, int $referenceType): string {
                self::assertSame(UrlGeneratorInterface::ABSOLUTE_URL, $referenceType);

                return \sprintf('https://example.com/%s%s', $route, [] === $parameters ? '' : '?'.http_build_query($parameters));
            },
        );

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            $this->warnings[] = [(string) $message, $context];
        });

        return new WaitlistOwnerNotifier(
            recipients: new WaitlistNotificationRecipients($flags),
            waitlistEntries: $repo,
            mailer: $mailer,
            translator: $translator,
            urlGenerator: new PinnedUrlGenerator($router, 'https://example.com'),
            logger: $logger,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Example',
            defaultLocale: 'en',
        );
    }
}
