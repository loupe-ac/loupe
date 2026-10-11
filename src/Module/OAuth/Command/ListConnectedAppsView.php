<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

final readonly class ListConnectedAppsView
{
    /**
     * @param list<ConnectedApp>    $apps          every app with a live grant, the widget's popup sign-in included
     * @param list<PreviewTokenRow> $previewTokens
     */
    public function __construct(
        public array $apps,
        public array $previewTokens,
    ) {
    }
}
