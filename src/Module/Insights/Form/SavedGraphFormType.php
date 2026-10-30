<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SavedGraphRequest> */
final class SavedGraphFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'required' => false,
            'label' => 'insights.form.saved_graph_form.name.label',
        ]);
        foreach (['metric', 'unit', 'statistic', 'group', 'range', 'bucket', 'compare', 'chart'] as $setting) {
            $builder->add($setting, HiddenType::class, ['required' => false]);
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SavedGraphRequest::class]);
    }
}
