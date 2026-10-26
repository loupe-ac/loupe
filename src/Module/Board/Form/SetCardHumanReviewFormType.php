<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SetCardHumanReviewRequest> */
final class SetCardHumanReviewFormType extends AbstractType
{
    public const string PREFIX = 'set_card_human_review_';

    public static function nameFor(string $cardId): string
    {
        return self::PREFIX.$cardId;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('requiresHumanReview', CheckboxType::class);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SetCardHumanReviewRequest::class]);
    }
}
