<?php

declare(strict_types=1);

namespace App\Module\GitHub\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The tickboxes of the repository picker. The page renders each tickbox by
 * hand, under the name of the collection, because the choices come from GitHub.
 *
 * @extends AbstractType<PickRepositoriesRequest>
 */
final class PickRepositoriesFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('repositories', CollectionType::class, [
            'entry_type' => TextType::class,
            'allow_add' => true,
            'error_bubbling' => true,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PickRepositoriesRequest::class]);
    }
}
