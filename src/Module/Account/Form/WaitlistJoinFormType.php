<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\WaitlistPick;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<WaitlistJoinRequest> */
final class WaitlistJoinFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'account.form.waitlist_join_form.email.label',
                'attr' => ['placeholder' => 'account.form.waitlist_join_form.email.placeholder', 'autocomplete' => 'email'],
            ])
            ->add('picks', ChoiceType::class, [
                'label' => 'account.form.waitlist_join_form.picks.label',
                'choices' => WaitlistPick::cases(),
                'choice_value' => static fn (?WaitlistPick $pick): ?string => $pick?->value,
                'choice_label' => static fn (WaitlistPick $pick): string => 'account.form.waitlist_join_form.picks.choice.'.$pick->value,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('other', TextType::class, [
                'label' => 'account.form.waitlist_join_form.other.label',
                'required' => false,
                'empty_data' => null,
                'attr' => ['placeholder' => 'account.form.waitlist_join_form.other.placeholder', 'maxlength' => 200],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WaitlistJoinRequest::class]);
    }
}
