<?php

declare(strict_types=1);

namespace App\Module\GitHub\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The project choice after an install from github.com. The choices are the ids of the projects the person owns,
 * plus `new`. The page draws the radio rows by hand, because each row names a project and its repositories.
 *
 * @extends AbstractType<ChooseInstallProjectRequest>
 */
final class ChooseInstallProjectFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('project', ChoiceType::class, [
                'choices' => array_combine($options['project_ids'], $options['project_ids']),
                'expanded' => true,
                'multiple' => false,
                'choice_translation_domain' => false,
                'invalid_message' => 'github.form.choose_install_project_form.project.invalid',
            ])
            ->add('name', TextType::class, [
                'label' => 'github.form.choose_install_project_form.name.label',
                'required' => false,
                'trim' => true,
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChooseInstallProjectRequest::class]);
        $resolver->setRequired('project_ids');
        $resolver->setAllowedTypes('project_ids', 'string[]');
    }
}
