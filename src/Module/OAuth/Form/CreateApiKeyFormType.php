<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use App\Module\OAuth\Entity\ApiKey;
use App\Module\OAuth\Entity\ApiKeyExpiry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<CreateApiKeyRequest> */
class CreateApiKeyFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'oauth.form.create_api_key_form.name.label',
                'help' => 'oauth.form.create_api_key_form.name.help',
                'attr' => ['autocomplete' => 'off', 'maxlength' => ApiKey::NAME_MAX_LENGTH],
            ])
            ->add('expiry', EnumType::class, [
                'class' => ApiKeyExpiry::class,
                'label' => 'oauth.form.create_api_key_form.expiry.label',
                'help' => 'oauth.form.create_api_key_form.expiry.help',
                'choice_label' => static fn (ApiKeyExpiry $expiry): string => match ($expiry) {
                    ApiKeyExpiry::ThirtyDays => 'oauth.form.create_api_key_form.expiry.thirty_days',
                    ApiKeyExpiry::NinetyDays => 'oauth.form.create_api_key_form.expiry.ninety_days',
                    ApiKeyExpiry::OneYear => 'oauth.form.create_api_key_form.expiry.one_year',
                    ApiKeyExpiry::Never => 'oauth.form.create_api_key_form.expiry.never',
                },
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateApiKeyRequest::class]);
    }
}
