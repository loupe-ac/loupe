<?php

declare(strict_types=1);

namespace App\Module\Notification\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Read by a fetch() from the settings page, so the fields sit at the top of the
 * body and the token field is plain `token`.
 *
 * @extends AbstractType<RegisterPushDeviceRequest>
 */
final class RegisterPushDeviceFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('endpoint', TextType::class)
            ->add('publicKey', TextType::class)
            ->add('authToken', TextType::class)
            ->add('contentEncoding', TextType::class)
            ->add('label', TextType::class);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegisterPushDeviceRequest::class,
            'csrf_field_name' => 'token',
        ]);
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
