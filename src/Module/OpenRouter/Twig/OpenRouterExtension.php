<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Twig;

use App\Mercure\ProjectTopicBuilder;
use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** A key section passes these topics to mercure_subscribe(), and reloads its status frame on a signal. */
final class OpenRouterExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserTopicBuilder $userTopics,
        private readonly ProjectTopicBuilder $projectTopics,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('openrouter_topic', $this->topic(...)),
        ];
    }

    public function topic(User|Project $owner): string
    {
        return $owner instanceof User
            ? $this->userTopics->forOpenRouter($owner->id ?? throw new \LogicException('User has no id.'))
            : $this->projectTopics->forOpenRouter($owner->requireId());
    }
}
