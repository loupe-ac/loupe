<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\AddWaitlistBlockPatternCommand;
use App\Module\Account\Command\Admin\AddWaitlistBlockPatternHandler;
use App\Module\Account\Form\Admin\AddWaitlistBlockPatternFormType;
use App\Module\Account\Form\Admin\AddWaitlistBlockPatternRequest;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/waitlist/block-patterns',
    name: 'app_admin_waitlist_block_pattern_add',
    methods: ['POST'],
)]
final class AddWaitlistBlockPatternController extends AppController
{
    public function __construct(
        private readonly AddWaitlistBlockPatternHandler $addBlockPattern,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $data = new AddWaitlistBlockPatternRequest();
        $form = $this->createForm(AddWaitlistBlockPatternFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->addBlockPattern)(new AddWaitlistBlockPatternCommand(
                    $data->pattern ?? throw new \LogicException('pattern required after validation'),
                ));
                $this->addFlash('success', $this->translator->trans('account.admin.waitlist.block_patterns.flash.added'));

                return $this->redirectToRoute('app_admin_waitlist_list');
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->forward(ListWaitlistController::class, [ListWaitlistController::BLOCK_PATTERN_FORM => $form->createView()])
            ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
