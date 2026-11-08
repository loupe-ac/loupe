<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Preview;

use Twig\Environment;
use Twig\Loader\ArrayLoader;

/** Records the context and the output of each render, and renders for real. */
final class RecordingTwig extends Environment
{
    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    /** @var list<string> */
    public array $outputs = [];

    public function __construct(
        private readonly Environment $inner,
    ) {
        parent::__construct(new ArrayLoader());
    }

    /** @param array<string, mixed> $context */
    #[\Override]
    public function render($name, array $context = []): string
    {
        $this->contexts[] = $context;

        return $this->outputs[] = $this->inner->render($name, $context);
    }
}
