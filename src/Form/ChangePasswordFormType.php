<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Step 3 of the password-reset flow — pick + repeat a new password.
 *
 * Constraints kept intentionally light: `NotBlank` plus a minimum
 * length of 8 characters, with no composition rules (mixed case,
 * digits, symbols) and no HIBP look-up. The value is
 * `mapped: false`; the controller reads it and hashes on the fly.
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'options' => [
                    'attr' => [
                        'autocomplete' => 'new-password',
                    ],
                ],
                'first_options' => [
                    'constraints' => [
                        new NotBlank(
                            message: 'security.reset_password.reset.password_required',
                        ),
                        new Length(
                            min: 8,
                            minMessage: 'security.reset_password.reset.password_min',
                            // max length allowed by Symfony for security reasons
                            max: 4096,
                        ),
                    ],
                    'label' => 'security.reset_password.reset.new_password_label',
                ],
                'second_options' => [
                    'label' => 'security.reset_password.reset.repeat_password_label',
                ],
                'invalid_message' => 'security.reset_password.reset.password_mismatch',
                // Read + hashed in the controller, not written back to any object.
                'mapped' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
