<?php

declare(strict_types=1);

namespace App\Module\Account\Twig;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** An account page passes this topic to mercure_subscribe() to hear the account.changed signals of its user. */
final class AccountTopicExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserTopicBuilder $topics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('account_topic', $this->topic(...)),
        ];
    }

    public function topic(User $user): string
    {
        return $this->topics->forAccount($user->id ?? throw new \LogicException('User has no id.'));
    }
}
