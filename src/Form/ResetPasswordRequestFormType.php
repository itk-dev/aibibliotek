<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Step 1 of the password-reset flow — the "which e-mail?" input.
 *
 * The template renders the input manually via `<twig:Form:TextInput>`
 * so the class only needs to declare the field's constraints; the
 * bundled scaffolded form was rendering through `form_row()`, which
 * doesn't reach the project's component look-and-feel.
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [
                    new NotBlank(
                        message: 'security.reset_password.request.email_required',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
