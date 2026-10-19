<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Service;

use App\Module\DesignSystem\ComponentEntry;
use Twig\Environment;

/** Renders the hand-written and generated files of one catalog component. */
final readonly class ComponentSource
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function jsx(ComponentEntry $entry): string
    {
        return $this->render($entry, 'component.jsx.twig');
    }

    public function types(ComponentEntry $entry): string
    {
        return $this->render($entry, 'component.d.ts.twig');
    }

    public function readme(ComponentEntry $entry): string
    {
        return $this->render($entry, 'component.prompt.md.twig');
    }

    /** The demo markup, a fragment of JSX. */
    public function demo(ComponentEntry $entry): string
    {
        return $this->twig->render('@DesignSystem/export/'.$this->folder($entry).(null === $entry->export ? 'default.demo.jsx.twig' : 'demo.jsx.twig'), $this->context($entry));
    }

    /**
     * The preview page. It mounts every component its demo draws.
     *
     * @param list<string> $names the names of all catalog components
     */
    public function preview(ComponentEntry $entry, array $names): string
    {
        $demo = $this->demo($entry);
        $uses = array_values(array_filter($names, static fn (string $name): bool => $name === $entry->name || 1 === preg_match('~<'.$name.'[\s/>]~', $demo)));
        [$width, $height] = explode('x', $entry->viewport) + [1 => '150'];

        return $this->twig->render('@DesignSystem/export/component.card.html.twig', $this->context($entry) + [
            'demo' => $demo,
            'uses' => $uses,
            'width' => $width,
            'height' => $height,
        ]);
    }

    private function render(ComponentEntry $entry, string $template): string
    {
        return $this->twig->render('@DesignSystem/export/'.$this->folder($entry).$template, $this->context($entry));
    }

    private function folder(ComponentEntry $entry): string
    {
        return null === $entry->export ? '' : $entry->group.'/'.$entry->export.'/';
    }

    /** @return array<string, mixed> */
    private function context(ComponentEntry $entry): array
    {
        return ['entry' => $entry, 'section' => ucfirst($entry->group)];
    }
}
