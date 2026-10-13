<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One wait of a wait item as the page shows it: a translation key with its values, and the link that closes the wait. */
final readonly class CardWaitLine
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public string $key,
        public array $params,
        public ?string $href,
        public ?string $detail,
        public ?string $titleKey = null,
        private ?string $action = null,
        private ?string $pageKey = null,
    ) {
    }

    /** The sentence the page shows, which leaves out the run output that the page shows below it. */
    public function pageKey(): string
    {
        return $this->pageKey ?? $this->key;
    }

    public function actionKey(): string
    {
        return $this->action ?? $this->key.'.action';
    }

    public function titleKey(): string
    {
        return $this->titleKey ?? $this->key.'.title';
    }
}
