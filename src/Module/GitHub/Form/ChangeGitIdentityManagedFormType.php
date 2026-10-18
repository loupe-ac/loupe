<?php

declare(strict_types=1);

namespace App\Module\GitHub\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The switch of the Git identity panel. The submit button turnOff serves the
 * link of the push refusal notice. The submit button save serves a reader
 * without JavaScript.
 *
 * @extends AbstractType<ChangeGitIdentityManagedRequest>
 */
final class ChangeGitIdentityManagedFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('managed', CheckboxType::class, [
                'required' => false,
                'label' => 'github.form.change_git_identity_managed_form.managed.label',
            ])
            ->add('save', SubmitType::class, ['label' => 'github.form.change_git_identity_managed_form.save.label'])
            ->add('turnOff', SubmitType::class, ['label' => 'github.form.change_git_identity_managed_form.turn_off.label']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChangeGitIdentityManagedRequest::class]);
    }
}
