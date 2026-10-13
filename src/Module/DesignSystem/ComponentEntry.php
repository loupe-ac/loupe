<?php

declare(strict_types=1);

namespace App\Module\DesignSystem;

/**
 * One building block of the design system. The styleguide renders it, the
 * adoption check counts its root class, and the Claude Design export lists it.
 */
final readonly class ComponentEntry
{
    /**
     * @param list<string> $variants
     * @param list<string> $states
     * @param list<string> $sizes
     * @param list<string> $legacyClasses  old class names this component replaces
     * @param bool         $legacyEnforced true once no template uses an old class: the adoption check then errors instead of warning
     * @param bool         $classOnly      true for a block that is a class on an existing tag, with no component template: the adoption check leaves it out
     */
    public function __construct(
        public string $name,
        public string $rootClass,
        public string $template,
        public array $variants,
        public array $states,
        public bool $enforced,
        public array $sizes = [],
        public string $group = 'core',
        public string $summary = '',
        public string $element = 'div',
        public string $dotClass = '',
        public string $contentClass = '',
        public array $legacyClasses = [],
        public bool $legacyEnforced = false,
        public bool $classOnly = false,
    ) {
    }
}
