<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\DataExportEmailSender;
use App\Routing\PinnedUrlGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DataExportEmailSenderTest extends TestCase
{
    public function test_send_builds_and_dispatches_the_download_email(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'hashed');
        $export = new DataExport($user);

        /** @var MailerInterface&MockObject $mailer */
        $mailer = $this->createMock(MailerInterface::class);
        $sentEmail = null;
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $router = $this->createMock(RouterInterface::class);
        $router->method('getContext')->willReturn(new RequestContext());
        $router->expects(self::once())
            ->method('generate')
            ->with(
                'app_account_export_download',
                self::callback(static fn (array $params): bool => 'raw-token-value' === $params['token']),
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://loupe.test/account/exports/'.$export->id.'/download?token=raw-token-value');
        $urlGenerator = new PinnedUrlGenerator($router, 'https://loupe.test');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $sender = new DataExportEmailSender(
            mailer: $mailer,
            translator: $translator,
            urlGenerator: $urlGenerator,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Loupe',
        );

        $sender->send($export, 'raw-token-value');

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame('account.email.data_export.subject', $sentEmail->getSubject());
        self::assertSame('alice@example.com', $sentEmail->getTo()[0]->getAddress());
        self::assertSame('@Account/email/data_export.html.twig', $sentEmail->getHtmlTemplate());
        self::assertStringContainsString('raw-token-value', (string) $sentEmail->getContext()['downloadUrl']);
    }
}
