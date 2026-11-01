<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One form for every action and every response of an item: the action is the address a button posts
 * to, and the note is the same text whichever button the owner presses. The placeholder comes from the template.
 * The reply card of a thread and the response form of the item are two copies of it, so each takes its own name.
 *
 * @extends AbstractType<RunInboxActionRequest>
 */
class RunInboxActionFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_action_'.$item->id;
    }

    /** The name of the form that carries the response of the owner: the answer, the verdict or the closing note. */
    public static function responseNameFor(InboxItem $item): string
    {
        return 'inbox_response_'.$item->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('note', TextareaType::class, ['required' => false]);
        $builder->add('sessionPrompt', TextareaType::class, ['required' => false]);
        $builder->add('branch', TextType::class, ['required' => false]);
        $builder->add('runDigest', TextType::class, ['required' => false]);
        // An empty value stays an empty string, so the parameter is checked against its pattern rather than dropped.
        $builder->add('params', CollectionType::class, [
            'entry_type' => TextType::class,
            'entry_options' => ['required' => false, 'empty_data' => ''],
            'allow_add' => true,
            'required' => false,
        ]);
        // A hidden field hands its errors to the form by default. Kept here, they render beside the options the fieldset describes.
        $builder->add('selectedOptions', HiddenType::class, [
            'required' => false,
            'error_bubbling' => false,
            'attr' => ['data-form-draft-target' => 'selectedOptions'],
        ]);
        $builder->add('verdict', ChoiceType::class, [
            'required' => false,
            'expanded' => true,
            'placeholder' => false,
            'label' => 'inbox.form.run_inbox_action_form.verdict.label',
            'choices' => [
                'inbox.form.run_inbox_action_form.verdict.approve' => 'approved',
                'inbox.form.run_inbox_action_form.verdict.changes' => 'changes-requested',
            ],
        ]);
        $builder->add('versionNumber', IntegerType::class, ['required' => false, 'label' => false]);
        $builder->add('expectedReviewId', HiddenType::class, ['required' => false]);
        $builder->add('expectedUrl', HiddenType::class, ['required' => false, 'error_bubbling' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RunInboxActionRequest::class]);
    }
}
