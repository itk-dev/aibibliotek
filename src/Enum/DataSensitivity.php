<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Classification for the data an {@see \App\Entity\Assistant}'s knowledge
 * base and prompt handle.
 *
 * Curators pick one entry on the create-wizard's metadata step; the value
 * is persisted verbatim on the row and drives reviewer guidance around
 * whether the assistant is safe to share with a wider audience. Every
 * case's short label and longer descriptive text is exposed as a
 * translation key via {@see self::label()} / {@see self::description()}
 * so form widgets and detail-page copy can call the translator without
 * duplicating the mapping.
 */
enum DataSensitivity: string
{
    /**
     * No personal data at all — public or purely factual material that
     * identifies nobody. Declared first so the least sensitive option
     * heads the radio-card list a curator reads top-down.
     */
    case NoPersonal = 'no_personal';

    /**
     * Ordinary personal information covered by GDPR articles 6 and 7 —
     * routine, non-sensitive personal data.
     */
    case OrdinaryPersonal = 'ordinary_personal';

    /**
     * Confidential data — not personal but requires organisational
     * safeguards (contracts, tender material, internal memos).
     */
    case Confidential = 'confidential';

    /**
     * Special-category personal data covered by GDPR article 9
     * (health, ethnicity, religious beliefs, etc.).
     */
    case SensitivePersonal = 'sensitive_personal';

    /**
     * Translation key for this case's short label.
     *
     * @return string a translator id under the default catalogue
     */
    public function label(): string
    {
        return 'assistant.data_sensitivity.'.$this->value.'.label';
    }

    /**
     * Translation key for this case's longer descriptive text.
     *
     * @return string a translator id under the default catalogue
     */
    public function description(): string
    {
        return 'assistant.data_sensitivity.'.$this->value.'.description';
    }

    /**
     * Translation key for this case's concrete example.
     *
     * The label names the category and the description states the rule;
     * neither tells a curator which bucket their own material falls in.
     * The example does, by naming the kind of document the category is
     * meant for, so the choice can be made by recognition rather than by
     * interpreting data-protection vocabulary.
     *
     * @return string a translator id under the default catalogue
     */
    public function example(): string
    {
        return 'assistant.data_sensitivity.'.$this->value.'.example';
    }
}
