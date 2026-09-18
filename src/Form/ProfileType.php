<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Self-service profile form at `/profile/edit`.
 *
 * Non-mapped — the controller hands the submission array to
 * {@see \App\Security\UserManager::updateUser()} keyed on the
 * authenticated user's own e-mail. The form intentionally
 * carries only the `name` field: e-mail is the identifier and
 * stays read-only, role / status are admin-only changes, and
 * the password flow lives outside this surface.
 */
final class ProfileType extends AbstractType
{
    private const string INPUT_CLASS = 'rounded-lg border border-line bg-surface px-3 py-2 text-base text-ink focus:outline-none focus:ring-2 focus:ring-primary/40';
    private const string LABEL_CLASS = 'block font-medium text-ink';
    private const string ROW_CLASS = 'grid gap-1 text-sm';

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'profile.edit.name_label',
            'required' => true,
            'empty_data' => '',
            'constraints' => [new NotBlank()],
            'attr' => ['class' => self::INPUT_CLASS, 'autocomplete' => 'name'],
            'label_attr' => ['class' => self::LABEL_CLASS],
            'row_attr' => ['class' => self::ROW_CLASS],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'empty_data' => ['name' => ''],
        ]);
    }
}
