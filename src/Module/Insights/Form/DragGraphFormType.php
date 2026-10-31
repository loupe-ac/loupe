<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<DragGraphRequest> */
final class DragGraphFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('steps', IntegerType::class, ['required' => false, 'label' => 'insights.form.drag_graph_form.steps.label']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DragGraphRequest::class]);
    }
}
