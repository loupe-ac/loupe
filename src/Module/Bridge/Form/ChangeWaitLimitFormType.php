<?php

declare(strict_types=1);

namespace App\Module\Bridge\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ChangeWaitLimitRequest>
 */
final class ChangeWaitLimitFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('minutes', IntegerType::class, [
            'label' => 'bridge.form.change_wait_limit_form.minutes.label',
            'help' => 'bridge.form.change_wait_limit_form.minutes.help',
            'help_translation_parameters' => ['%max%' => $options['max_minutes']],
            'attr' => ['min' => 1, 'max' => $options['max_minutes']],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangeWaitLimitRequest::class,
            'max_minutes' => 120,
        ]);
        $resolver->setAllowedTypes('max_minutes', 'int');
    }
}
