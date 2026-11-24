<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\InboxLimits;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The owner picks one of the other options or writes a choice, and may add a note.
 * Each row of the Decisions tab has its own copy, so each takes its own name.
 *
 * @extends AbstractType<ChangeInboxDecisionRequest>
 */
class ChangeInboxDecisionFormType extends AbstractType
{
    public static function nameFor(InboxDecision $decision): string
    {
        return 'inbox_decision_'.$decision->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($options['other_options'] as $index => $text) {
            $choices[$text] = (string) $index;
        }
        $builder->add('option', ChoiceType::class, [
            'required' => false,
            'expanded' => true,
            'placeholder' => false,
            'choices' => $choices,
            'choice_translation_domain' => false,
            'label' => 'inbox.form.change_inbox_decision_form.option.label',
        ]);
        $builder->add('custom', TextType::class, [
            'required' => false,
            'label' => 'inbox.form.change_inbox_decision_form.custom.label',
            'attr' => ['maxlength' => InboxLimits::MAX_OPTION_LENGTH],
        ]);
        $builder->add('note', TextareaType::class, [
            'required' => false,
            'label' => 'inbox.form.change_inbox_decision_form.note.label',
            'attr' => ['rows' => 2],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChangeInboxDecisionRequest::class, 'other_options' => []]);
        $resolver->setAllowedTypes('other_options', 'array');
    }
}
