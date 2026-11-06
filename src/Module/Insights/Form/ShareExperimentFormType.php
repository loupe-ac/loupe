<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ShareExperimentRequest> */
final class ShareExperimentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'insights.form.share_experiment_form.enabled.label',
            ])
            ->add('note', TextareaType::class, [
                'required' => false,
                'label' => 'insights.form.share_experiment_form.note.label',
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ShareExperimentRequest::class]);
    }
}
