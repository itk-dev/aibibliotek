<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Assistant;
use App\Entity\Organization;
use App\Entity\Tag;
use App\Enum\DataSensitivity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class AssistantTest extends TestCase
{
    // Tests that the constructor assigns each field and attaches the given tags as a collection.
    public function testConstructorPopulatesFieldsAndAttachesTags(): void
    {
        $translation = new Tag('translation');
        $summarisation = new Tag('summarisation');

        $assistant = new Assistant(
            'Title',
            'Description',
            'gpt-4o',
            'OpenAI',
            [$translation, $summarisation],
        );

        self::assertInstanceOf(Ulid::class, $assistant->getId());
        self::assertSame('Title', $assistant->getTitle());
        self::assertSame('Description', $assistant->getDescription());
        self::assertSame('gpt-4o', $assistant->getLanguageModel());
        self::assertSame('OpenAI', $assistant->getFramework());
        self::assertSame([$translation, $summarisation], $assistant->getTags()->toArray());
    }

    // Verifies that omitting the tags argument leaves getTags() returning an empty collection.
    public function testConstructorDefaultsTagsToEmptyCollection(): void
    {
        $assistant = new Assistant('t', 'd', 'lm', 'fw');

        self::assertCount(0, $assistant->getTags());
    }

    // Ensures the constructor de-duplicates a tag passed twice, attaching it only once.
    public function testConstructorIgnoresDuplicateTags(): void
    {
        $tag = new Tag('jura');

        $assistant = new Assistant('t', 'd', 'lm', 'fw', [$tag, $tag]);

        self::assertSame([$tag], $assistant->getTags()->toArray());
    }

    // Tests that each scalar setter mutates its field and returns `$this`.
    public function testSettersMutateAndReturnStatic(): void
    {
        $assistant = new Assistant('t', 'd', 'lm', 'fw');

        self::assertSame($assistant, $assistant->setTitle('new title'));
        self::assertSame('new title', $assistant->getTitle());

        self::assertSame($assistant, $assistant->setDescription('new description'));
        self::assertSame('new description', $assistant->getDescription());

        self::assertSame($assistant, $assistant->setLanguageModel('claude-opus-4-7'));
        self::assertSame('claude-opus-4-7', $assistant->getLanguageModel());

        self::assertSame($assistant, $assistant->setFramework('Anthropic'));
        self::assertSame('Anthropic', $assistant->getFramework());
    }

    // Verifies addTag() attaches once (idempotent) and returns `$this`, and removeTag() detaches.
    public function testAddAndRemoveTag(): void
    {
        $assistant = new Assistant('t', 'd', 'lm', 'fw');
        $tag = new Tag('borgerservice');

        self::assertSame($assistant, $assistant->addTag($tag));
        self::assertSame($assistant, $assistant->addTag($tag), 'adding a present tag is a no-op');
        self::assertSame([$tag], $assistant->getTags()->toArray());

        self::assertSame($assistant, $assistant->removeTag($tag));
        self::assertCount(0, $assistant->getTags());
    }

    // Verifies the metadata-field constructor arguments land verbatim on the entity and default to null when omitted.
    public function testMetadataFieldsRoundTripThroughConstructorAndAccessors(): void
    {
        $organization = new Organization('Aarhus Kommune', ['aarhus.dk'], 'openwebui');

        $assistant = new Assistant(
            'Title',
            'Description',
            'gpt-4o',
            'openwebui',
            [],
            $organization,
            'Tagline',
            'Videns-grundlaget beskrives.',
            DataSensitivity::Confidential,
        );

        self::assertSame($organization, $assistant->getOrganization());
        self::assertSame('Tagline', $assistant->getTagline());
        self::assertSame('Videns-grundlaget beskrives.', $assistant->getKnowledgeDescription());
        self::assertSame(DataSensitivity::Confidential, $assistant->getDataSensitivity());

        $blank = new Assistant('t', 'd', 'lm', 'fw');
        self::assertNull($blank->getOrganization());
        self::assertNull($blank->getTagline());
        self::assertNull($blank->getKnowledgeDescription());
        self::assertNull($blank->getDataSensitivity());
    }

    // Verifies each metadata-field setter mutates its column and returns `$this`.
    public function testMetadataFieldSettersMutateAndReturnStatic(): void
    {
        $assistant = new Assistant('t', 'd', 'lm', 'fw');
        $organization = new Organization('Odense Kommune', ['odense.dk'], 'openwebui');

        self::assertSame($assistant, $assistant->setOrganization($organization));
        self::assertSame($organization, $assistant->getOrganization());

        self::assertSame($assistant, $assistant->setTagline('Ny tagline'));
        self::assertSame('Ny tagline', $assistant->getTagline());

        self::assertSame($assistant, $assistant->setKnowledgeDescription('Ny beskrivelse'));
        self::assertSame('Ny beskrivelse', $assistant->getKnowledgeDescription());

        self::assertSame($assistant, $assistant->setDataSensitivity(DataSensitivity::OrdinaryPersonal));
        self::assertSame(DataSensitivity::OrdinaryPersonal, $assistant->getDataSensitivity());
    }
}
