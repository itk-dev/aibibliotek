<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\DataSensitivity;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seed twenty-one assistants for local development.
 *
 * Six are hand-written catalogue entries — five are authentic rows
 * drawn from the AI Bibliotek prototype, and the sixth is a tagless
 * row used to cover the detail page's empty-tags branch in tests.
 * The remaining fifteen are generated deterministically from a fixed
 * set of topics, kommuner and language models — same input on every
 * run, no randomness — so test assertions and design previews stay
 * reproducible.
 */
final class AssistantFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * Belong to the `default` group so the Woodpecker stg pipeline can
     * load the general fixture set without also seeding
     * {@see LocalUserFixtures}' personal-inbox accounts (`--group=default`
     * then `--group=local --append`).
     *
     * @return list<string> group identifiers the fixtures bundle filters on
     */
    public static function getGroups(): array
    {
        return ['default'];
    }

    /**
     * Per-load de-duplication cache of tag name → managed {@see Tag}.
     *
     * Tags are shared across assistants, so the same name must resolve to
     * one entity instance; {@see self::tags()} populates this on first use
     * and reuses it thereafter. The relation cascades persist, so the
     * cached tags are written when their owning assistant is flushed —
     * no explicit `persist()` (which would also break the unit test's
     * Assistant-only mock).
     *
     * @var array<string, Tag>
     */
    private array $tagCache = [];

    public function load(ObjectManager $manager): void
    {
        // Reset the tag cache so a reused fixture instance still produces
        // fresh, unmanaged tags rather than entities from a prior load.
        $this->tagCache = [];

        // The two fixture users own the catalogue round-robin; resolved once
        // and reused so every assistant shares the same managed instances.
        $creators = FixtureCreators::resolve($manager);

        // Resolve the sharing organizations up front so the detailed and
        // generated batches can attach each row's kommune without touching
        // the manager repeatedly. `resolveOrganizations()` returns an
        // empty array when the manager can't hydrate them (the unit test
        // uses a mocked object manager), so a null organization is fine.
        $organizations = $this->resolveOrganizations($manager);

        // A running index across both batches drives the round-robin
        // creator assignment: detailed entries take 0–5, generated 6–20.
        $index = $this->loadDetailed($manager, $creators, $organizations, 0);
        $this->loadGenerated($manager, $creators, $organizations, $index);
        $manager->flush();
    }

    /**
     * Resolve `name → Organization` for the seeded organisations.
     *
     * Load whatever organisations {@see OrganizationFixtures} has already
     * persisted and index them by name so detailed entries can attach
     * the matching organisation without hard-coding a ULID. Returns an
     * empty array when the manager can't hydrate rows (the unit test
     * uses a mocked object manager whose repository returns an empty
     * result set) — detailed rows then fall back to a null organisation.
     *
     * @param ObjectManager $manager object manager used to look up organisations
     *
     * @return array<string, Organization> keyed by `Organization::name`
     */
    private function resolveOrganizations(ObjectManager $manager): array
    {
        $repository = $manager->getRepository(Organization::class);
        $organizations = $repository->findAll();
        $byName = [];
        foreach ($organizations as $organization) {
            \assert($organization instanceof Organization);
            $byName[$organization->getName()] = $organization;
        }

        return $byName;
    }

    /**
     * Declare that users must be loaded first.
     *
     * The creating users are looked up by e-mail in {@see FixtureCreators},
     * so {@see UserFixtures} has to run — and commit alice and bob — before
     * this fixture.
     *
     * @return array<class-string> the fixture classes this one depends on
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class, OrganizationFixtures::class];
    }

    /**
     * Persist the six hand-written catalogue entries.
     *
     * @param ObjectManager               $manager       Doctrine object manager the entries are persisted into
     * @param list<User>                  $creators      round-robin creators, or empty when users are unavailable
     * @param array<string, Organization> $organizations resolved organizations keyed by name (may be empty in unit tests)
     * @param int                         $index         running index of the first entry, for creator round-robin
     *
     * @return int the next free index after the persisted entries
     */
    private function loadDetailed(ObjectManager $manager, array $creators, array $organizations, int $index): int
    {
        $entries = [
            new Assistant(
                title: 'Borgerservice-vejviser',
                description: 'Hjælper sagsbehandlere i borgerservice med at finde den rigtige paragraf i lov om social service og lov om aktiv socialpolitik. Tager udgangspunkt i en kort beskrivelse af borgerens situation og foreslår relevante lovhjemler, sagskategorier og næste skridt. Indeholder kommunens egne vejledninger og praksisnotater som baggrundsviden. Delt af Aarhus Kommune.',
                languageModel: 'gpt-4o',
                framework: 'openwebui',
                tags: $this->tags(['borgerservice', 'social', 'jura']),
                organization: $organizations['Aarhus Kommune'] ?? null,
                tagline: 'Foreslår paragrafhjemler i sociale sager.',
                knowledgeDescription: 'Kommunens egne vejledninger og praksisnotater samt lov om social service og lov om aktiv socialpolitik.',
                dataSensitivity: DataSensitivity::Confidential,
            ),
            new Assistant(
                title: 'Mødereferent',
                description: 'Tager udgangspunkt i et indtalt eller transskriberet mødeoptag og leverer et struktureret referat med beslutninger, ansvarsfordeling og deadlines. Identificerer automatisk handlepunkter og foreslår opfølgningstidspunkter. Bruges på direktionsmøder, projektmøder og udvalgsmøder. Delt af Københavns Kommune.',
                languageModel: 'gpt-4o-mini',
                framework: 'openwebui',
                tags: $this->tags(['mødeledelse', 'dokumentation', 'produktivitet']),
                organization: null,
                tagline: 'Genererer strukturerede mødereferater med handlepunkter.',
                knowledgeDescription: 'Mødeoptag / transskriptioner. Ingen ekstern videns- eller dataindlæsning ud over selve mødets indhold.',
                dataSensitivity: DataSensitivity::Confidential,
            ),
            new Assistant(
                title: 'Journaliseringsassistent',
                description: 'Foreslår journalplan-numre og overskrifter ud fra dokumentets indhold, så fagmedarbejdere kan godkende i ét klik. Tager højde for kommunens egen klassifikationsstruktur og henter forslag fra historiske, lignende sager. Reducerer den tid medarbejdere bruger på korrekt arkivering markant. Delt af Odense Kommune.',
                languageModel: 'llama-3.1',
                framework: 'openwebui',
                tags: $this->tags(['dokumentation', 'journalisering', 'arkiv']),
                organization: $organizations['Odense Kommune'] ?? null,
                tagline: 'Foreslår journalplan-numre til godkendelse i ét klik.',
                knowledgeDescription: 'Kommunens klassifikationsstruktur og et anonymiseret udsnit af historiske sager med journalplan-numre.',
                dataSensitivity: DataSensitivity::OrdinaryPersonal,
            ),
            new Assistant(
                title: 'Skole- og dagtilbudssvar',
                description: 'Drafter svar til forældrehenvendelser på skole- og dagtilbudsområdet. Bygger svaret på kommunens egen vejledningssamling, gældende lovgivning på området og det specifikke dagtilbuds praksis. Vedhæfter kildehenvisninger så medarbejderen kan tjekke baggrunden inden afsendelse. Delt af Vejle Kommune.',
                languageModel: 'llama-3.2',
                framework: 'openwebui',
                tags: $this->tags(['skole', 'dagtilbud', 'kommunikation']),
                organization: null,
                tagline: 'Drafter svar til forældrehenvendelser med kildehenvisninger.',
                knowledgeDescription: 'Kommunens vejledningssamling på skole- og dagtilbudsområdet, gældende lovgivning og det enkelte dagtilbuds praksisnotater.',
                dataSensitivity: DataSensitivity::OrdinaryPersonal,
            ),
            new Assistant(
                title: 'Tilsynsrapport-assistent',
                description: 'Læser plejehjemstilsynsrapporter og fremhæver afvigelser, opfølgningspunkter og udvikling over tid. Sammenligner det enkelte plejehjems resultater med kommune- og landsgennemsnit og foreslår fokusområder til det næste tilsyn. Bygger på Styrelsen for Patientsikkerheds tilsynsdata. Delt af Aalborg Kommune.',
                languageModel: 'mistral',
                framework: 'openwebui',
                tags: $this->tags(['sundhed', 'tilsyn', 'plejehjem']),
                organization: $organizations['Aalborg Kommune'] ?? null,
                tagline: 'Fremhæver afvigelser og opfølgningspunkter i tilsynsrapporter.',
                knowledgeDescription: 'Styrelsen for Patientsikkerheds tilsynsdata suppleret med den enkelte kommunes plejehjemsdata.',
                dataSensitivity: DataSensitivity::SensitivePersonal,
            ),
            new Assistant(
                title: 'Uden kategorier',
                description: 'Pladsholder uden tags — bruges til at vise hvordan detaljevisningen håndterer en helt umarkeret post.',
                languageModel: 'gpt-4o',
                framework: 'openwebui',
                organization: null,
                tagline: null,
                knowledgeDescription: null,
                dataSensitivity: DataSensitivity::OrdinaryPersonal,
            ),
        ];

        foreach ($entries as $assistant) {
            FixtureCreators::assign($creators, $assistant, $index++);
            $manager->persist($assistant);
        }

        return $index;
    }

    /**
     * Persist the fifteen deterministically generated entries.
     *
     * @param ObjectManager               $manager       Doctrine object manager the entries are persisted into
     * @param list<User>                  $creators      round-robin creators, or empty when users are unavailable
     * @param array<string, Organization> $organizations resolved organizations keyed by name (may be empty in unit tests)
     * @param int                         $index         running index of the first entry, for creator round-robin
     */
    private function loadGenerated(ObjectManager $manager, array $creators, array $organizations, int $index): void
    {
        $topics = [
            [
                'title' => 'HR-håndbog assistent',
                'description' => 'Slår op i kommunens personalehåndbog og besvarer spørgsmål om ferie, sygdomsregler og overenskomster med citater fra kilden.',
                'tags' => ['hr', 'personale'],
            ],
            [
                'title' => 'Indkøbsguide',
                'description' => 'Hjælper indkøbsansvarlige med at finde gældende rammeaftaler, foreslå relevante leverandører og generere udkast til rekvisitioner.',
                'tags' => ['indkøb', 'udbud'],
            ],
            [
                'title' => 'Politisk dagsorden-resumé',
                'description' => 'Læser udvalgs- og byrådsdagsordener og leverer letlæste resuméer med beslutningspunkter, høringssvar og baggrundsmateriale.',
                'tags' => ['politik', 'dagsorden'],
            ],
            [
                'title' => 'Forvaltningsret-vejviser',
                'description' => 'Vejleder sagsbehandlere i forvaltningsrettens grundprincipper med praksisnotater og henvisninger til relevante lovparagraffer.',
                'tags' => ['jura', 'sagsbehandling'],
            ],
            [
                'title' => 'Sundhedsfaglig sparring',
                'description' => 'Faglig sparringspartner for hjemmeplejen — kvalitetssikrer plejeplaner og foreslår dokumentationsforbedringer ud fra Sundhedsstyrelsens retningslinjer.',
                'tags' => ['sundhed', 'hjemmepleje'],
            ],
            [
                'title' => 'Borgerhenvendelse-svarudkast',
                'description' => 'Drafter udkast til svar på borgermails ud fra kommunens egne vejledninger og gældende lovgivning, så medarbejderen kan rette til og godkende.',
                'tags' => ['borgerservice', 'kommunikation'],
            ],
            [
                'title' => 'Statistikfortolker',
                'description' => 'Læser kommunens KPI-rapporter og foreslår tekstuelle forklaringer på udsving samt sammenligninger med foregående perioder og kommunegennemsnit.',
                'tags' => ['statistik', 'rapportering'],
            ],
        ];

        $kommunes = [
            'Aarhus Kommune',
            'Københavns Kommune',
            'Odense Kommune',
            'Vejle Kommune',
            'Aalborg Kommune',
            'Esbjerg Kommune',
            'Frederiksberg Kommune',
            'Randers Kommune',
            'Kolding Kommune',
            'Horsens Kommune',
        ];

        // Canonical model ids drawn from config/model_map.yaml so
        // the fixtures use the same shortlist the picker + exporter
        // recognise. Order kept stable so the deterministic round-
        // robin below produces the same title/model pairing every
        // load.
        $languageModels = [
            'gpt-4o',
            'gpt-4o-mini',
            'o3-mini',
            'llama-3.1',
            'llama-3.2',
            'mistral',
        ];

        // Data-sensitivity rotates through the three enum cases so every
        // classification is represented in the seeded catalogue — useful
        // for design review and for tests that count buckets. The order
        // matches the enum declaration so the rotation stays predictable.
        $sensitivities = [
            DataSensitivity::OrdinaryPersonal,
            DataSensitivity::Confidential,
            DataSensitivity::SensitivePersonal,
        ];

        $topicCount = count($topics);
        $kommuneCount = count($kommunes);
        $modelCount = count($languageModels);
        $sensitivityCount = count($sensitivities);

        for ($i = 0; $i < 15; ++$i) {
            $topic = $topics[$i % $topicCount];
            $kommune = $kommunes[$i % $kommuneCount];
            $languageModel = $languageModels[$i % $modelCount];
            $sensitivity = $sensitivities[$i % $sensitivityCount];

            $assistant = new Assistant(
                title: $topic['title'].' – '.$kommune,
                description: $topic['description'].' Delt af '.$kommune.'.',
                languageModel: $languageModel,
                framework: 'openwebui',
                tags: $this->tags($topic['tags']),
                organization: $organizations[$kommune] ?? null,
                tagline: $topic['title'].' — genereret demo-post.',
                knowledgeDescription: 'Genereret demo-post. Videns- og datagrundlag udfyldes normalt af den delende kommune ved oprettelse.',
                dataSensitivity: $sensitivity,
            );
            FixtureCreators::assign($creators, $assistant, $index + $i);
            $manager->persist($assistant);
        }
    }

    /**
     * Resolve a list of tag names to shared {@see Tag} entities.
     *
     * De-duplicates through {@see self::$tagCache} so a name used by more
     * than one assistant maps to a single entity, satisfying the tag
     * table's unique-name constraint. The tags are not persisted here;
     * the assistant's cascade persists them on flush.
     *
     * @param list<string> $names tag names to resolve, in order
     *
     * @return list<Tag> the matching tag entities, one per input name
     */
    private function tags(array $names): array
    {
        $tags = [];
        foreach ($names as $name) {
            $tags[] = $this->tagCache[$name] ??= new Tag($name);
        }

        return $tags;
    }
}
