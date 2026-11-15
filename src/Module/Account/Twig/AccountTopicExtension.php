<?php

declare(strict_types=1);

namespace App\Module\Account\Twig;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A page that follows the account of the signed-in user passes this topic to mercure_subscribe(). */
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
            new TwigFunction('account_topic', $this->accountTopic(...)),
        ];
    }

    public function accountTopic(User $user): string
    {
        return $this->topics->forAccount($user->id ?? throw new \LogicException('User has no id.'));
    }
}
