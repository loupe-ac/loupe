<?php

declare(strict_types=1);

namespace App\Module\Notification\Twig;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Notification\Command\ListPushDeviceWarningsCommand;
use App\Module\Notification\Command\ListPushDeviceWarningsHandler;
use App\Module\Notification\Entity\PushDevice;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A page that shows push devices passes the topic to mercure_subscribe(), and a
 * page that shows the stopped device warning reads its first state here.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserTopicBuilder $topics,
        private readonly ListPushDeviceWarningsHandler $listWarnings,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('notifications_topic', $this->topic(...)),
            new TwigFunction('stopped_device_warnings', $this->warnings(...)),
        ];
    }

    public function topic(User $user): string
    {
        return $this->topics->forNotifications($user->id ?? throw new \LogicException('User has no id.'));
    }

    /** @return list<PushDevice> */
    public function warnings(User $user): array
    {
        return ($this->listWarnings)(new ListPushDeviceWarningsCommand($user))->devices;
    }
}
