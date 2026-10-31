<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GraphLegendRequest> */
final class GraphLegendFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('show', ChoiceType::class, [
            'choices' => [true, false],
            'required' => false,
            'label' => 'insights.form.graph_legend_form.show.label',
            'choice_value' => static fn (?bool $show): string => null === $show ? '' : ($show ? 'on' : 'off'),
            'choice_label' => static fn (bool $show): string => 'insights.form.graph_legend_form.show.'.($show ? 'on' : 'off'),
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GraphLegendRequest::class]);
    }
}
