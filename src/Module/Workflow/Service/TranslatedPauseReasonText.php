<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\PauseReasonText;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(PauseReasonText::class)]
final readonly class TranslatedPauseReasonText implements PauseReasonText
{
    private const string PAUSE_PREFIX = 'workflow.pause.';

    private const string REFUSAL_PREFIX = 'workflow.refusal.';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function forPause(string $code): string
    {
        return $this->lookup([self::PAUSE_PREFIX, self::REFUSAL_PREFIX], $code);
    }

    #[\Override]
    public function forRefusal(string $code): string
    {
        return $this->lookup([self::REFUSAL_PREFIX], $code);
    }

    /** @param list<string> $prefixes */
    private function lookup(array $prefixes, string $code): string
    {
        foreach ($prefixes as $prefix) {
            $text = $this->translator->trans($prefix.$code);
            if ($text !== $prefix.$code) {
                return $text;
            }
        }

        return $code;
    }
}
