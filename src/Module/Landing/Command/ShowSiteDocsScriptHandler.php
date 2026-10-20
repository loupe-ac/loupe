<?php

declare(strict_types=1);

namespace App\Module\Landing\Command;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final readonly class ShowSiteDocsScriptHandler
{
    public function __construct(
        private Environment $twig,

        #[Autowire('%kernel.project_dir%/src/Module/Landing/Resources/site-docs.js')]
        private string $scriptPath,
    ) {
    }

    /** @return string the config assignment, then the static script that reads it */
    public function __invoke(): string
    {
        $script = file_get_contents($this->scriptPath);
        if (false === $script) {
            throw new \RuntimeException('The docs script source is missing.');
        }

        return 'var loupeSiteDocs = '.trim($this->twig->render('@Landing/show_site_docs_script.html.twig')).";\n".$script;
    }
}
