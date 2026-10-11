<?php

declare(strict_types=1);

namespace App\Module\OAuth\Twig;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Board must not import OAuth, so the card page calls this function for its preview sign-in panel. */
final class PreviewSignInExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly PreviewTokenRepository $previewTokens,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('oauth_preview_sign_in_panel', $this->panel(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('preview_token_days_left', self::daysLeft(...)),
        ];
    }

    public function panel(Environment $twig, Project $project, Uuid $cardId): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->id || !$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            return '';
        }

        return $twig->render('@OAuth/_card_preview_sign_in.html.twig', [
            'tokens' => $this->previewTokens->findLiveForUserAndCard($user->id, $cardId),
        ]);
    }

    /** Days left, rounded up so a new token reads 14. Less than one day left reads 0. */
    public static function daysLeft(\DateTimeImmutable $expiresAt, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $seconds = $expiresAt->getTimestamp() - $now->getTimestamp();

        return $seconds < 86400 ? 0 : intdiv($seconds + 86399, 86400);
    }
}
