<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\DevTemplateMarkerNodeVisitor;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\EmptyNode;

final class DevTemplateMarkerNodeVisitorTest extends TestCase
{
    // Tests that a non-extending template's body is wrapped in opening and closing template-name markers.
    public function testWrapsTopLevelBodyWithMarkers(): void
    {
        $output = $this->render(['hello.html.twig' => '<p>hi</p>']);

        self::assertSame(
            '<!-- hello.html.twig --><p>hi</p><!-- /hello.html.twig -->',
            $output,
        );
    }

    // Verifies that an extending template's `body` block is wrapped inside the parent template's own markers.
    public function testWrapsBodyBlockOfExtendingTemplate(): void
    {
        $output = $this->render([
            'base.html.twig' => '[{% block body %}{% endblock %}]',
            'child.html.twig' => '{% extends "base.html.twig" %}{% block body %}hi{% endblock %}',
        ], 'child.html.twig');

        // base.html.twig is itself a non-extending template and also gets
        // its body wrapped. child.html.twig's markers live inside the
        // `body` block, between base's markers.
        self::assertSame(
            '<!-- base.html.twig -->[<!-- child.html.twig -->hi<!-- /child.html.twig -->]<!-- /base.html.twig -->',
            $output,
        );
    }

    // Ensures an extending template that does not override `body` contributes no markers of its own.
    public function testExtendingTemplateWithoutBodyBlockIsLeftAlone(): void
    {
        $output = $this->render([
            'base.html.twig' => '[{% block other %}fallback{% endblock %}]',
            'child.html.twig' => '{% extends "base.html.twig" %}{% block other %}hi{% endblock %}',
        ], 'child.html.twig');

        // No `body` block in the chain, so child.html.twig contributes no
        // markers. Base still gets its own.
        self::assertSame('<!-- base.html.twig -->[hi]<!-- /base.html.twig -->', $output);
    }

    // Tests that templates loaded from a Twig namespace (e.g. `@vendor/…`) are not wrapped.
    public function testNamespacedTemplateIsSkipped(): void
    {
        $output = $this->render(['@vendor/widget.html.twig' => '<p>vendor</p>']);

        self::assertSame('<p>vendor</p>', $output);
    }

    // Verifies enterNode() returns the node unchanged (the visitor only acts in leaveNode()).
    public function testEnterNodeIsAPassThrough(): void
    {
        $visitor = new DevTemplateMarkerNodeVisitor();
        $env = new Environment(new ArrayLoader([]));
        $node = new EmptyNode();

        self::assertSame($node, $visitor->enterNode($node, $env));
    }

    // Ensures leaveNode() leaves non-ModuleNode nodes unchanged so unrelated nodes don't get wrapped.
    public function testLeaveNodeIgnoresNonModuleNodes(): void
    {
        $visitor = new DevTemplateMarkerNodeVisitor();
        $env = new Environment(new ArrayLoader([]));
        $node = new EmptyNode();

        self::assertSame($node, $visitor->leaveNode($node, $env));
    }

    // Tests that getPriority() returns 0 so the visitor runs at Twig's default position in the node-visitor chain.
    public function testPriorityIsZero(): void
    {
        self::assertSame(0, (new DevTemplateMarkerNodeVisitor())->getPriority());
    }

    /**
     * @param array<string, string> $templates
     */
    private function render(array $templates, ?string $name = null): string
    {
        $env = new Environment(new ArrayLoader($templates), ['cache' => false]);
        $env->addNodeVisitor(new DevTemplateMarkerNodeVisitor());

        return $env->render($name ?? array_key_first($templates));
    }
}
