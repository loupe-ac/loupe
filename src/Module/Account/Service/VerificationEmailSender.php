<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Routing\PinnedUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

class VerificationEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly PinnedUrlGenerator $urlGenerator,

        #[Autowire(param: 'app.mailer.from_address')]
        private readonly string $mailerFromAddress,

        #[Autowire(param: 'app.mailer.from_name')]
        private readonly string $mailerFromName,
    ) {
    }

    public function send(User $user): void
    {
        $token = $user->generateEmailVerificationToken();
        $this->em->flush();

        $signedUrl = $this->urlGenerator->generate(
            'app_verify_email',
            ['token' => $token],
        );

        $email = new TemplatedEmail()
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($user->email)
            ->subject($this->translator->trans('account.email.verification.subject'))
            ->htmlTemplate('@Account/email/verification.html.twig')
            ->context(['signedUrl' => $signedUrl]);

        $this->mailer->send($email);
    }
}
