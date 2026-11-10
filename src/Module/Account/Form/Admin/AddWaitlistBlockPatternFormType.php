<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use App\Module\Account\Entity\WaitlistBlockPattern;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AddWaitlistBlockPatternRequest>
 */
final class AddWaitlistBlockPatternFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pattern', TextType::class, [
            'label' => 'account.form.add_waitlist_block_pattern_form.pattern.label',
            'help' => 'account.form.add_waitlist_block_pattern_form.pattern.help',
            'attr' => [
                'maxlength' => WaitlistBlockPattern::MAX_LENGTH,
                'placeholder' => 'account.form.add_waitlist_block_pattern_form.pattern.placeholder',
                'autocomplete' => 'off',
            ],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddWaitlistBlockPatternRequest::class,
        ]);
    }
}
