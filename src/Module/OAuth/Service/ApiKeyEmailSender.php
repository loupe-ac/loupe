<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\OAuth\Entity\ApiKey;
use App\Routing\PinnedUrlGenerator;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/** A mail names the key, its start and its end date. The whole key never reaches a mail. */
class ApiKeyEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly PinnedUrlGenerator $urlGenerator,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    public function sendCreated(ApiKey $key): void
    {
        $this->send($key, 'oauth.email.api_key_created.subject', '@OAuth/email/api_key_created.html.twig');
    }

    public function sendExpiring(ApiKey $key): void
    {
        $this->send($key, 'oauth.email.api_key_expiring.subject', '@OAuth/email/api_key_expiring.html.twig');
    }

    private function send(ApiKey $key, string $subjectKey, string $template): void
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($key->owner->email)
            ->subject($this->translator->trans($subjectKey, ['%name%' => $key->name]))
            ->htmlTemplate($template)
            ->context([
                'name' => $key->name,
                'displayStart' => $key->displayStart,
                'expiresAt' => $key->expiresAt,
                'keysUrl' => $this->urlGenerator->generate('app_account_api_keys'),
            ]);

        $this->mailer->send($email);
    }
}
