<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use App\Validator\ValidationResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for one import/export format (OpenWebUI, and later others).
 *
 * An adapter owns everything format-specific: how to recognise its
 * wire format, how to validate it, how to reduce it to the neutral
 * {@see CanonicalModel}, and how to render a canonical model back out.
 * The rest of the app talks to adapters only through
 * {@see FormatAdapterRegistry}, so adding a format is "implement this
 * interface and let autoconfiguration register it" — no changes to the
 * wizard, creator, or export controller.
 *
 * Validation is split three ways on purpose: the AJAX upload UI and
 * the form constraint need a **non-throwing** result
 * ({@see self::validate()} / {@see self::runCheck()}), while the
 * persistence path wants a **throwing** parse ({@see self::parseToSource()}).
 */
#[AutoconfigureTag('app.format_adapter')]
interface FormatAdapter
{
    /**
     * The stable machine id of this format (e.g. `openwebui`).
     *
     * Doubles as the assistant's framework/source-format discriminator
     * and as the key in {@see FormatAdapterRegistry::all()}.
     *
     * @return string the format id
     */
    public function id(): string;

    /**
     * Human-readable label for this format (e.g. `Open WebUI`).
     *
     * @return string the display label
     */
    public function label(): string;

    /**
     * Whether this format's import/export is experimental.
     *
     * OpenWebUI is the manually-verified, supported format; the others
     * are best-effort and unverified, so the UI can caution the user
     * before they rely on a round-trip through them.
     *
     * @return bool true when the format is experimental
     */
    public function isExperimental(): bool;

    /**
     * MIME type of the exported payload (e.g. `application/json`).
     *
     * @return string the media type for the download response
     */
    public function mediaType(): string;

    /**
     * File extension (without the dot) for the exported payload.
     *
     * @return string the download filename extension (e.g. `json`)
     */
    public function fileExtension(): string;

    /**
     * Cheap, non-throwing test of whether `$raw` is this format.
     *
     * Used by {@see FormatAdapterRegistry::detect()} to pick the
     * adapter for an upload. Must not throw and must be inexpensive.
     *
     * @param string $raw the raw uploaded payload
     *
     * @return bool true when this adapter recognises `$raw`
     */
    public function supports(string $raw): bool;

    /**
     * Ordered list of validation check identifiers for this format.
     *
     * Drives the AJAX upload UI's per-check progress. Adapter-owned:
     * a JSON format may expose `['syntax','schema']`, a text format a
     * single check.
     *
     * @return list<string> the check identifiers, in run order
     */
    public function getChecks(): array;

    /**
     * Run a single named check against `$raw`, without throwing.
     *
     * @param string $name one of {@see self::getChecks()}
     * @param string $raw  the raw uploaded payload
     *
     * @return ValidationResult the result of that check
     *
     * @throws \InvalidArgumentException when `$name` is not a registered check
     */
    public function runCheck(string $name, string $raw): ValidationResult;

    /**
     * Run every check and aggregate the result, without throwing.
     *
     * @param string $raw the raw uploaded payload
     *
     * @return ValidationResult valid when all checks pass, else the aggregated errors
     */
    public function validate(string $raw): ValidationResult;

    /**
     * Validate and reduce `$raw` to the sanitised source dict stored
     * on the assistant.
     *
     * The returned array is the format's own shape, stripped of PII /
     * instance data — it is what lands in `assistant.source_config`.
     *
     * @param string $raw the raw uploaded payload
     *
     * @return array<string, mixed> the sanitised, format-specific source dict
     *
     * @throws \App\Assistant\InvalidAssistantInputException when `$raw` fails validation
     */
    public function parseToSource(string $raw): array;

    /**
     * The canonical fields this format needs to render a payload that
     * re-imports cleanly.
     *
     * The create wizard unions these across every registered format (see
     * {@see FormatAdapterRegistry::requiredForAnyExport()}) to decide which
     * fields the curator must supply, so an assistant imported from a
     * sparse format can still be exported into a stricter one.
     *
     * @return list<string> canonical field names, matching
     *                      {@see CanonicalModel} properties (e.g. `name`,
     *                      `baseModel`)
     */
    public function requiredCanonicalFields(): array;

    /**
     * Convert a stored source dict to the neutral canonical model.
     *
     * @param array<string, mixed> $source a dict as produced by {@see self::parseToSource()}
     *
     * @return CanonicalModel the format-neutral model
     */
    public function sourceToCanonical(array $source): CanonicalModel;

    /**
     * Render a canonical model into this format's source dict.
     *
     * For a same-format export the adapter re-injects the matching
     * entry from {@see CanonicalModel::$sourceExtras}; for a
     * cross-format export those extras are foreign and dropped.
     *
     * @param CanonicalModel $model the model to render
     *
     * @return array<string, mixed> this format's source dict
     */
    public function canonicalToSource(CanonicalModel $model): array;

    /**
     * Encode a source dict to the downloadable wire string.
     *
     * @param array<string, mixed> $source a dict as produced by {@see self::canonicalToSource()}
     *
     * @return string the serialised payload for download
     */
    public function serialize(array $source): string;
}
