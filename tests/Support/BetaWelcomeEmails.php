<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Service\BetaWelcomeEmailSender;
use App\Site\SiteLinks;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the welcome email sender over the real repository and connection but
 * a mailer the test controls, so a test can make the queue write fail.
 */
final class BetaWelcomeEmails
{
    public const string DISCORD = 'https://discord.gg/example';

    public static function sender(
        ContainerInterface $container,
        MailerInterface $mailer,
        TranslatorInterface $translator,
        string $discord = self::DISCORD,
        LoggerInterface $logger = new NullLogger(),
    ): BetaWelcomeEmailSender {
        return new BetaWelcomeEmailSender(
            mailer: $mailer,
            translator: $translator,
            betaInvites: $container->get(BetaInviteRepository::class),
            connection: $container->get(Connection::class),
            siteLinks: new SiteLinks(FeatureFlags::service([SiteLinks::DISCORD_FLAG => $discord]), $logger, ''),
            router: $container->get(RouterInterface::class),
            logger: $logger,
            mailerFromAddress: 'noreply@example.com',
            mailerFromName: 'Loupe',
        );
    }
}
