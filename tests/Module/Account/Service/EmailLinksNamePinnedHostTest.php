<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Service\DataExportEmailSender;
use App\Module\Account\Service\VerificationEmailSender;
use App\Module\Account\Service\WaitlistInviteEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\RouterInterface;

/** A link in email names the address in DEFAULT_URI, whatever host the sending request carried. */
final class EmailLinksNamePinnedHostTest extends KernelTestCase
{
    private const string PINNED = 'http://localhost/';

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);
        $router->getContext()->setHost('evil.example')->setScheme('https');
    }

    /** The first link of the email body whose address contains the path. */
    private function link(string $path): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $body = (string) $email->getHtmlBody();
        self::assertStringNotContainsString('evil.example', $body);
        if (1 !== preg_match('#href="([^"]*'.preg_quote($path, '#').'[^"]*)"#', $body, $matches)) {
            self::fail('No link to '.$path);
        }

        return html_entity_decode($matches[1]);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Pinned Links', $email, 'hashed');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_the_verification_link_names_the_pinned_host(): void
    {
        static::getContainer()->get(VerificationEmailSender::class)->send($this->user('pinned-verify@example.com'));

        self::assertStringStartsWith(self::PINNED, $this->link('/verify'));
    }

    public function test_the_waitlist_invite_link_names_the_pinned_host(): void
    {
        static::getContainer()->get(WaitlistInviteEmailSender::class)->send(new WaitlistEntry('pinned-invite@example.com'), 'plain-token');

        self::assertStringStartsWith(self::PINNED, $this->link('invite=plain-token'));
    }

    public function test_the_data_export_link_names_the_pinned_host(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $export = new DataExport($this->user('pinned-export@example.com'));
        $em->persist($export);
        $em->flush();

        static::getContainer()->get(DataExportEmailSender::class)->send($export, 'raw-token');

        self::assertStringStartsWith(self::PINNED, $this->link('raw-token'));
    }
}
