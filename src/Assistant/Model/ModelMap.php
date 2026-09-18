<?php

declare(strict_types=1);

namespace App\Assistant\Model;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Cross-system base-model translation, backed by `config/model_map.yaml`.
 *
 * A `CanonicalModel` carries a single base-model string, but each target
 * system names its models differently and some models have no equivalent
 * elsewhere. This service is the source of truth for that translation: it
 * folds the spellings a source format might carry onto a neutral canonical
 * id ({@see self::normalise()}) and resolves that id to the string a given
 * target system expects ({@see self::toTarget()}).
 *
 * Unknown models pass through verbatim (the `fallback: passthrough`
 * policy) and a known model with no equivalent in a target resolves to
 * null, so the exporter can pass the name through and warn rather than
 * silently substituting a different — behaviourally different — model.
 *
 * The map is read once from disk and memoised. Structural integrity of the
 * shipped file (labels present, aliases unique, target ids valid) is
 * asserted by a dedicated test so a typo fails CI rather than mis-mapping
 * silently.
 */
final class ModelMap
{
    /**
     * The only supported strategy for models absent from the map.
     */
    private const string FALLBACK_PASSTHROUGH = 'passthrough';

    /**
     * Canonical model definitions keyed by canonical id; null until loaded.
     *
     * @var array<string, array{label: string, targets: array<string, string|null>, aliases: list<string>}>|null
     */
    private ?array $models = null;

    /**
     * Normalised alias/key => canonical id lookup; null until loaded.
     *
     * @var array<string, string>|null
     */
    private ?array $aliasIndex = null;

    /**
     * @param string $mapPath absolute path to the model-map YAML
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/model_map.yaml')]
        private readonly string $mapPath,
    ) {
    }

    /**
     * Fold a raw source model string onto its canonical id.
     *
     * Matching is case-insensitive and whitespace-trimmed against both the
     * canonical ids and their aliases.
     *
     * @param string $raw a model string as a source format carries it
     *
     * @return string|null the canonical id, or null when the model is unknown
     */
    public function normalise(string $raw): ?string
    {
        $this->load();

        return $this->aliasIndex[$this->key($raw)] ?? null;
    }

    /**
     * Whether a raw model string maps to a known canonical model.
     *
     * @param string $raw a model string as a source format carries it
     *
     * @return bool true when {@see self::normalise()} resolves it
     */
    public function isKnown(string $raw): bool
    {
        return null !== $this->normalise($raw);
    }

    /**
     * Resolve a model to the id a given target format expects.
     *
     * An unknown model is passed through verbatim (fallback policy); a known
     * model with no equivalent in `$formatId` resolves to null so the caller
     * can warn instead of substituting a different model.
     *
     * @param string $model    a model string (raw or canonical)
     * @param string $formatId the target format id (e.g. `openai`)
     *
     * @return string|null the target-valid model id, the raw string when
     *                     unknown, or null when known-but-no-equivalent
     */
    public function toTarget(string $model, string $formatId): ?string
    {
        $canonical = $this->normalise($model);
        if (null === $canonical) {
            return $model;
        }

        return $this->models[$canonical]['targets'][$formatId] ?? null;
    }

    /**
     * The known models as `label => canonical id`, for a form choice list.
     *
     * @return array<string, string> label => canonical id, in map order
     */
    public function choices(): array
    {
        $this->load();

        $choices = [];
        foreach ($this->models as $id => $definition) {
            $choices[$definition['label']] = $id;
        }

        return $choices;
    }

    /**
     * The human label for a canonical id, or null when it is unknown.
     *
     * @param string $canonicalId a canonical model id
     *
     * @return string|null the label, or null when the id is not in the map
     */
    public function label(string $canonicalId): ?string
    {
        $this->load();

        return $this->models[$canonicalId]['label'] ?? null;
    }

    /**
     * The alias spellings recognised for a canonical id.
     *
     * The canonical id itself is not repeated in the returned list —
     * only the aliases as declared in `config/model_map.yaml`. Feeds
     * client-side search on the picker so typing an alias filters the
     * dropdown to the canonical entry.
     *
     * @param string $canonicalId a canonical model id
     *
     * @return list<string> declared aliases in map order; empty when
     *                      the id has no aliases or is unknown
     */
    public function aliasesFor(string $canonicalId): array
    {
        $this->load();

        return $this->models[$canonicalId]['aliases'] ?? [];
    }

    /**
     * Read, validate structurally, and memoise the model map.
     *
     * Only the load-blocking failures throw (the file must parse to a
     * mapping, declare the supported fallback, and carry a `models`
     * mapping); per-model shape is trusted here and asserted by the map's
     * integrity test. Aliases and the canonical id itself index onto the
     * canonical id; a later duplicate silently wins (the integrity test
     * forbids duplicates in the shipped file).
     *
     * @throws \RuntimeException when the map is missing, unparseable, or
     *                           structurally unusable
     */
    private function load(): void
    {
        if (null !== $this->models) {
            return;
        }

        $parsed = Yaml::parseFile($this->mapPath);
        if (!\is_array($parsed)) {
            throw new \RuntimeException(\sprintf('Model map at "%s" did not decode to a mapping.', $this->mapPath));
        }

        if (self::FALLBACK_PASSTHROUGH !== ($parsed['fallback'] ?? null)) {
            throw new \RuntimeException(\sprintf('Model map at "%s" must set "fallback: passthrough".', $this->mapPath));
        }

        $models = $parsed['models'] ?? null;
        if (!\is_array($models)) {
            throw new \RuntimeException(\sprintf('Model map at "%s" must define a "models" mapping.', $this->mapPath));
        }

        $definitions = [];
        $index = [];
        foreach ($models as $id => $definition) {
            $id = (string) $id;
            $definition = (array) $definition;
            $label = isset($definition['label']) && \is_string($definition['label']) ? $definition['label'] : $id;

            $targets = [];
            foreach ((array) ($definition['targets'] ?? []) as $formatId => $targetId) {
                $targets[(string) $formatId] = \is_string($targetId) ? $targetId : null;
            }
            $aliases = array_values(array_filter((array) ($definition['aliases'] ?? []), 'is_string'));
            $definitions[$id] = ['label' => $label, 'targets' => $targets, 'aliases' => $aliases];

            foreach ([$id, ...$aliases] as $spelling) {
                $index[$this->key($spelling)] = $id;
            }
        }

        $this->models = $definitions;
        $this->aliasIndex = $index;
    }

    /**
     * Normalise a model string to its lookup key (trimmed, lower-cased).
     *
     * @param string $value a canonical id, alias, or raw model string
     *
     * @return string the normalised lookup key
     */
    private function key(string $value): string
    {
        return strtolower(trim($value));
    }
}
