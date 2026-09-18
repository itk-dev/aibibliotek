<?php

declare(strict_types=1);

namespace App\Form;

use App\Assistant\Format\FormatAdapterRegistry;
use App\Entity\Organization;
use App\Validator\SupportedFramework;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form type backing the admin Organization CRUD.
 *
 * The {@see Organization} constructor requires every field, so this
 * form supplies an `empty_data` factory that builds a fresh entity
 * from the submitted values for the "create" path; the "edit" path
 * uses the existing entity's setters.
 *
 * `emailDomains` is presented as a textarea with one domain per line
 * and transformed to / from the entity's `list<string>` shape.
 */
final class OrganizationType extends AbstractType
{
    private const string INPUT_CLASS = 'rounded-lg border border-line bg-surface px-3 py-2 text-base text-ink focus:outline-none focus:ring-2 focus:ring-primary/40';
    private const string LABEL_CLASS = 'block font-medium text-ink';
    private const string ROW_CLASS = 'grid gap-1 text-sm';

    /**
     * @param FormatAdapterRegistry $formats registry feeding the `defaultFramework` `<select>` choices + validator
     */
    public function __construct(private readonly FormatAdapterRegistry $formats)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'admin.organization.form.name_label',
                'required' => true,
                'empty_data' => '',
                'constraints' => [new NotBlank()],
                'attr' => ['class' => self::INPUT_CLASS],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('emailDomains', TextareaType::class, [
                'label' => 'admin.organization.form.email_domains_label',
                'help' => 'admin.organization.form.email_domains_help',
                'required' => true,
                'empty_data' => '',
                'constraints' => [new Count(min: 1, minMessage: 'admin.organization.form.email_domains_required')],
                'attr' => ['class' => self::INPUT_CLASS, 'rows' => 4],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->add('defaultFramework', ChoiceType::class, [
                'label' => 'admin.organization.form.default_framework_label',
                'help' => 'admin.organization.form.default_framework_help',
                'choices' => array_flip($this->formats->all()),
                'placeholder' => false,
                'required' => true,
                'empty_data' => $this->formats->default(),
                'constraints' => [new SupportedFramework()],
                'attr' => ['class' => self::INPUT_CLASS],
                'label_attr' => ['class' => self::LABEL_CLASS],
                'row_attr' => ['class' => self::ROW_CLASS],
            ])
            ->get('emailDomains')->addModelTransformer(new CallbackTransformer(
                static fn (?array $domains): string => null === $domains ? '' : implode("\n", $domains),
                static fn (?string $text): array => self::splitLines((string) $text),
            ))
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Organization::class,
            'empty_data' => static fn (FormInterface $form): Organization => new Organization(
                (string) $form->get('name')->getData(),
                (array) $form->get('emailDomains')->getData(),
                (string) $form->get('defaultFramework')->getData(),
            ),
        ]);
    }

    /**
     * Split textarea input into a list of trimmed, non-empty lines.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), $lines),
            static fn (string $line): bool => '' !== $line,
        ));
    }
}
