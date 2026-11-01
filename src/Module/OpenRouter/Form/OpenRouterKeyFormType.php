<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<OpenRouterKeyRequest> */
class OpenRouterKeyFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // PasswordType never writes its value back into the page, so a failed save does not echo the key.
        $builder->add('secret', PasswordType::class, [
            'label' => 'openrouter.form.open_router_key_form.secret.label',
            'always_empty' => true,
            'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'openrouter.form.open_router_key_form.secret.placeholder'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OpenRouterKeyRequest::class]);
    }
}
