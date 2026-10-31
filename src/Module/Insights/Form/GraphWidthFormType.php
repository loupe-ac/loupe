<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use App\Module\Insights\Entity\SavedGraphWidth;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GraphWidthRequest> */
final class GraphWidthFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('width', EnumType::class, [
            'class' => SavedGraphWidth::class,
            'required' => false,
            'label' => 'insights.form.graph_width_form.width.label',
            'choice_label' => static fn (SavedGraphWidth $width): string => 'insights.form.graph_width_form.width.'.$width->value,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GraphWidthRequest::class]);
    }
}
