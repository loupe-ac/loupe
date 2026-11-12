<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\Admin\ListWaitlistBlockPatternsCommand;
use App\Module\Account\Command\Admin\ListWaitlistBlockPatternsHandler;
use App\Module\Account\Form\Admin\AddWaitlistBlockPatternFormType;
use App\Module\Account\Form\Admin\AddWaitlistBlockPatternRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/waitlist/block-patterns',
    name: 'app_admin_waitlist_block_pattern_list',
    methods: ['GET'],
)]
final class ListWaitlistBlockPatternsController extends AppController
{
    public const string BLOCK_PATTERN_FORM = 'blockPatternForm';

    public function __construct(
        private readonly ListWaitlistBlockPatternsHandler $listBlockPatterns,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $view = ($this->listBlockPatterns)(new ListWaitlistBlockPatternsCommand());

        return $this->render('@Account/admin/list_waitlist_block_patterns.html.twig', [
            'blockPatterns' => $view->blockPatterns,
            'hiddenCount' => $view->hiddenCount,
            'blockPatternForm' => $this->getInjectedFormView($request, self::BLOCK_PATTERN_FORM)
                ?? $this->createForm(AddWaitlistBlockPatternFormType::class, new AddWaitlistBlockPatternRequest())->createView(),
        ]);
    }
}
