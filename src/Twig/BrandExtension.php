<?php

declare(strict_types=1);

namespace App\Twig;

use App\Settings\SettingsManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the brand identity globals (`brand_name`,
 * `brand_tagline`, `brand_initials`), the frontpage hero copy
 * (`hero_text`), and the design-palette globals
 * (`palette_text`, `palette_line`, `palette_text_muted`) to
 * every Twig template.
 *
 * Brand identity and `hero_text` delegate to {@see SettingsManager}
 * so admin-typed values in the `Setting` table win over their
 * respective defaults (`BRAND_*` env vars for the identity
 * fields; the `frontpage.hero.lead` translation key for the
 * hero copy). Palette values are constants mirroring the CSS
 * `@theme` tokens declared in `assets/styles/app.css`; they
 * exist as Twig globals so contexts that cannot read CSS
 * variables (HTML email, inline styles) reference one source.
 */
final class BrandExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * Primary text colour. Mirrors `--color-text` in `assets/styles/app.css`.
     */
    public const string PALETTE_TEXT = '#2c2c2c';

    /**
     * Divider / hairline colour. Mirrors `--color-line` in `assets/styles/app.css`.
     */
    public const string PALETTE_LINE = '#e9e9e9';

    /**
     * Muted secondary text colour. Mirrors `--color-text-muted` in `assets/styles/app.css`.
     */
    public const string PALETTE_TEXT_MUTED = '#585858';

    /**
     * @param SettingsManager $settings typed accessor for runtime-editable settings
     */
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    /**
     * Resolve the brand and palette globals at render time.
     *
     * Symfony calls `getGlobals()` for each environment instance
     * Twig builds; the values are resolved on each call so updates
     * persisted through the admin form take effect on the next
     * request without a cache clear.
     *
     * @return array{brand_name: string, brand_tagline: string, brand_initials: string, hero_text: string, palette_text: string, palette_line: string, palette_text_muted: string}
     */
    public function getGlobals(): array
    {
        return [
            'brand_name' => $this->settings->getBrandName(),
            'brand_tagline' => $this->settings->getBrandTagline(),
            'brand_initials' => $this->settings->getBrandInitials(),
            'hero_text' => $this->settings->getHeroText(),
            'palette_text' => self::PALETTE_TEXT,
            'palette_line' => self::PALETTE_LINE,
            'palette_text_muted' => self::PALETTE_TEXT_MUTED,
        ];
    }
}
