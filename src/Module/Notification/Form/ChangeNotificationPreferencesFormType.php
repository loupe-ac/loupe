<?php

declare(strict_types=1);

namespace App\Module\Notification\Form;

use App\Module\Notification\NotificationChannel;
use App\Module\Notification\NotificationChoice;
use App\Module\Notification\NotificationEvent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One switch for every cell of the grid. The form submits on change, and the
 * submit button serves a reader without JavaScript.
 *
 * @extends AbstractType<ChangeNotificationPreferencesRequest>
 */
final class ChangeNotificationPreferencesFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = $builder->create('choices', FormType::class, ['label' => false]);
        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationChannel::cases() as $channel) {
                $choices->add(NotificationChoice::key($event, $channel), CheckboxType::class, [
                    'required' => false,
                    'label' => false,
                    'disabled' => $options['disabled_cells'],
                ]);
            }
        }

        $builder
            ->add($choices)
            ->add('save', SubmitType::class, ['label' => 'notification.form.change_notification_preferences_form.save.label']);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangeNotificationPreferencesRequest::class,
            'disabled_cells' => false,
        ]);
        $resolver->setAllowedTypes('disabled_cells', 'bool');
    }
}
