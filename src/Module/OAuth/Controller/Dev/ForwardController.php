<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller\Dev;

use App\Controller\AppController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shows the forwarding page that follows Allow or Deny on the consent screen.
 * A preview link cannot post the consent form, so this route renders the same
 * template with a fixed target. #[When('dev')] keeps it out of production.
 */
#[Route(
    '/dev/oauth/forward/{outcome}',
    name: 'dev_oauth_forward_preview',
    requirements: ['outcome' => 'allow|deny'],
    methods: ['GET'],
)]
#[When('dev')]
final class ForwardController extends AppController
{
    public function __invoke(string $outcome): Response
    {
        $target = 'allow' === $outcome
            ? 'https://client.example/callback?code=preview&state=abc'
            : 'https://client.example/callback?error=access_denied&state=abc';

        $page = $this->render('@OAuth/forward.html.twig', ['target' => $target]);
        $page->headers->set('Cache-Control', 'no-store');

        return $page;
    }
}
