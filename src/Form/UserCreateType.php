<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\UserStatus;
use App\Security\Roles;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form type backing the admin "create user" surface at
 * `/admin/users/new`.
 *
 * Non-mapped — values land in an associative array which the
 * controller hands to {@see \App\Security\UserManager::createUser()}
 * so the password is hashed and the row is persisted in the
 * service layer.
 *
 * `roles` is a multi-checkbox over the application-defined roles
 * other than the implicit `ROLE_USER` floor; `status` is the
 * lifecycle enum from {@see UserStatus}.
 */
final class UserCreateType extends AbstractType
{
    private const string INPUT_CLASS = 'rounded-lg border border-line bg-surface px-3 py-2 text-base text-ink focus:outline-none focus:ring-2 focus:ring-primary/40';
    private const string LABEL_CLASS = 'block font-medium text-ink';
    private const string ROW_CLASS = 'grid gap-1 text-sm';

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'admin.users.form.email_label',
                'required' => true,
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
                'attr' => ['class' => self::INPUT_CLASS, 'autocomplete' => 'email'],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('name', TextType::class, [
                'label' => 'admin.users.form.name_label',
                'required' => true,
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'attr' => ['class' => self::INPUT_CLASS, 'autocomplete' => 'name'],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'admin.users.form.password_label',
                'required' => true,
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'attr' => ['class' => self::INPUT_CLASS, 'autocomplete' => 'new-password'],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'admin.users.form.roles_label',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'admin.users.form.roles.domain_manager' => Roles::DOMAIN_MANAGER,
                    'admin.users.form.roles.admin' => Roles::ADMIN,
                ],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('status', EnumType::class, [
                'label' => 'admin.users.form.status_label',
                'required' => true,
                'class' => UserStatus::class,
                'choice_label' => static fn (UserStatus $status): string => 'admin.users.status.'.$status->value,
                'attr' => ['class' => self::INPUT_CLASS],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Non-mapped: submission becomes an associative array.
            'data_class' => null,
            'empty_data' => [
                'email' => '',
                'name' => '',
                'password' => '',
                'roles' => [],
                'status' => UserStatus::Approved,
            ],
        ]);
    }
}
