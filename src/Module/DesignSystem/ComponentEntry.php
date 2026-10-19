<?php

declare(strict_types=1);

namespace App\Module\DesignSystem;

/**
 * One building block of the design system. The styleguide renders it, the
 * adoption check counts its root class, and the Design System artifact export lists it.
 *
 * A null `template` marks a part with documented classes and no Twig component.
 * `export` names a folder under `templates/Module/DesignSystem/export/<group>/`
 * that holds the hand-written wrapper of a part with an inner structure.
 * `viewport` is the width and height of its preview card, `700x150` by default.
 * `modifierProp` names the wrapper prop that picks a modifier class, the same
 * name as the Twig prop. `takesAs` marks a component whose Twig template takes `as`.
 * `attributes` holds the fixed attributes that the Twig template sets on its root.
 */
final readonly class ComponentEntry
{
    /**
     * @param list<string>          $variants
     * @param list<string>          $states
     * @param list<string>          $sizes
     * @param array<string, string> $attributes
     * @param list<string>          $legacyClasses  old class names this component replaces
     * @param bool                  $legacyEnforced true once no template uses an old class: the adoption check then errors instead of warning
     * @param bool                  $classOnly      true for a block that is a class on an existing tag, with no component template: the adoption check leaves it out
     */
    public function __construct(
        public string $name,
        public string $rootClass,
        public ?string $template,
        public array $variants,
        public array $states,
        public bool $enforced,
        public array $sizes = [],
        public string $group = 'core',
        public string $summary = '',
        public string $element = 'div',
        public string $dotClass = '',
        public string $contentClass = '',
        public ?string $export = null,
        public string $viewport = '700x150',
        public string $modifierProp = 'variant',
        public bool $takesAs = false,
        public array $attributes = [],
        public array $legacyClasses = [],
        public bool $legacyEnforced = false,
        public bool $classOnly = false,
    ) {
    }
}
