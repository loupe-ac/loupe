<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\Admin\RemoveWaitlistBlockPatternCommand;
use App\Module\Account\Command\Admin\RemoveWaitlistBlockPatternHandler;
use App\Module\Account\Entity\WaitlistBlockPattern;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-waitlist-block-pattern-remove')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/waitlist/block-patterns/{id:pattern}/remove',
    name: 'app_admin_waitlist_block_pattern_remove',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class RemoveWaitlistBlockPatternController extends AppController
{
    public function __construct(
        private readonly RemoveWaitlistBlockPatternHandler $removeBlockPattern,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(WaitlistBlockPattern $pattern): Response
    {
        ($this->removeBlockPattern)(new RemoveWaitlistBlockPatternCommand($pattern));
        $this->addFlash('success', $this->translator->trans('account.admin.waitlist.block_patterns.flash.removed'));

        return $this->redirectToRoute('app_admin_waitlist_block_pattern_list');
    }
}
