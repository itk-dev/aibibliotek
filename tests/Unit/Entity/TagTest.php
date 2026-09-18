<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Tag;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class TagTest extends TestCase
{
    // Tests that the constructor assigns the name and the inherited ULID id.
    public function testConstructorPopulatesNameAndId(): void
    {
        $tag = new Tag('borgerservice');

        self::assertInstanceOf(Ulid::class, $tag->getId());
        self::assertSame('borgerservice', $tag->getName());
    }

    // Ensures setName() mutates the name and returns `$this`.
    public function testSetNameMutatesAndReturnsStatic(): void
    {
        $tag = new Tag('jura');

        self::assertSame($tag, $tag->setName('social'));
        self::assertSame('social', $tag->getName());
    }

    // Verifies the tag stringifies to its name.
    public function testStringifiesToName(): void
    {
        self::assertSame('sundhed', (string) new Tag('sundhed'));
    }
}
