<?php

declare(strict_types=1);

namespace App\Module\Landing\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The script that each docs page loads. Every visitor gets the same bytes: the
 * browser reads the signed-in cookie, and the server reads nothing and varies
 * nothing, so a shared cache may keep one copy.
 */
#[Route('/site/docs.js', name: 'app_site_docs_script', methods: ['GET'])]
class ShowSiteDocsScriptController extends AppController
{
    public function __invoke(): Response
    {
        $response = $this->render('@Landing/show_site_docs_script.html.twig');
        $response->headers->set('Content-Type', 'text/javascript; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
