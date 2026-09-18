<?php

declare(strict_types=1);

namespace App\Assistant\Format;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The single source of truth for the set of import/export formats.
 *
 * Every {@see FormatAdapter} is autoconfigured into this registry via
 * the `app.format_adapter` tag, so the registry's members define the
 * framework taxonomy (catalogue facet, admin default-framework choices,
 * label lookups) as well as which uploads can be parsed. Detection
 * order follows the injected iterator order, which is the tag priority
 * order — a more specific format should be given a higher priority than
 * a permissive one so {@see self::detect()} stays deterministic.
 */
final class FormatAdapterRegistry
{
    /**
     * @var array<string, FormatAdapter> adapters keyed by id, in detection order
     */
    private array $adapters = [];

    /**
     * @param iterable<FormatAdapter> $adapters the tagged format adapters, in priority order
     */
    public function __construct(
        #[AutowireIterator('app.format_adapter')]
        iterable $adapters,
    ) {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->id()] = $adapter;
        }
    }

    /**
     * Fetch the adapter for a format id.
     *
     * @param string $id a format id (e.g. `openwebui`)
     *
     * @return FormatAdapter the matching adapter
     *
     * @throws \InvalidArgumentException when no adapter has that id
     */
    public function get(string $id): FormatAdapter
    {
        return $this->adapters[$id] ?? throw new \InvalidArgumentException(\sprintf('Unknown format "%s".', $id));
    }

    /**
     * Return the first adapter that recognises `$raw`, or null.
     *
     * Adapters are tried in detection order; the first whose
     * {@see FormatAdapter::supports()} returns true wins.
     *
     * @param string $raw the raw uploaded payload
     *
     * @return FormatAdapter|null the detected adapter, or null when none match
     */
    public function detect(string $raw): ?FormatAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($raw)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Whether a format id is registered.
     *
     * @param string $id a format id
     *
     * @return bool true when an adapter has that id
     */
    public function has(string $id): bool
    {
        return isset($this->adapters[$id]);
    }

    /**
     * Map of format id to human label, in registration order.
     *
     * Suitable as `ChoiceType` choices (label => id via array_flip) and
     * as the catalogue framework facet's label source.
     *
     * @return array<string, string> id => label
     */
    public function all(): array
    {
        return array_map(static fn (FormatAdapter $a): string => $a->label(), $this->adapters);
    }

    /**
     * Resolve a format id to its label, falling back to the id itself.
     *
     * The fallback keeps legacy rows carrying an unregistered format id
     * displayable rather than blank.
     *
     * @param string $id a format id
     *
     * @return string the adapter label, or `$id` when unregistered
     */
    public function label(string $id): string
    {
        return ($this->adapters[$id] ?? null)?->label() ?? $id;
    }

    /**
     * The union of every registered format's required canonical fields.
     *
     * The create wizard uses this to decide which canonical fields a
     * curator must supply, so an assistant imported from a sparse format
     * can still be exported into any registered format and re-imported
     * there. Order follows first appearance across adapters.
     *
     * @return list<string> the deduplicated required canonical field names
     */
    public function requiredForAnyExport(): array
    {
        $fields = [];
        foreach ($this->adapters as $adapter) {
            foreach ($adapter->requiredCanonicalFields() as $field) {
                $fields[$field] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * The install-wide default format id (the first registered), or an
     * empty string when no adapter is registered.
     *
     * @return string the default format id
     */
    public function default(): string
    {
        foreach ($this->adapters as $id => $_adapter) {
            return $id;
        }

        return '';
    }
}
