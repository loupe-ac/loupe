<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SetCardValueRequest> */
final class SetCardValueFormType extends AbstractType
{
    public const string PREFIX = 'set_card_value_';

    /** One form for each key of each card, so the rows of a page keep distinct ids and names. */
    public static function nameFor(string $cardId, string $key): string
    {
        return self::PREFIX.$cardId.'_'.$key;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('value', CheckboxType::class);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SetCardValueRequest::class]);
    }
}
