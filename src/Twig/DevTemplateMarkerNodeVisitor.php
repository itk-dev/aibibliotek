<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\TextNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Inject HTML-comment markers around each compiled template's output.
 *
 * Wraps every project template at compile time so the rendered HTML
 * shows `<!-- name -->` / `<!-- /name -->` around each template
 * boundary. Templates that `extends` another are wrapped at their
 * `body` block instead, because their top-level body never runs at
 * render time.
 *
 * Registered only in the dev environment by
 * {@see DevTemplateMarkerExtension::getNodeVisitors()}; the visitor
 * itself trusts the registration and unconditionally wraps every
 * non-namespaced template it sees.
 */
final class DevTemplateMarkerNodeVisitor implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    /**
     * Wrap the template body (or its `body` block, for extending
     * templates) with begin and end marker TextNodes.
     *
     * @param Node        $node the node being left
     * @param Environment $env  the Twig environment (unused)
     *
     * @return Node the original node, mutated in place when applicable
     */
    public function leaveNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof ModuleNode) {
            return $node;
        }

        $name = $node->getTemplateName();
        if (null === $name || str_starts_with($name, '@')) {
            // Skip framework / vendor templates loaded via a Twig namespace.
            return $node;
        }

        $line = $node->getTemplateLine();
        $prefix = new TextNode('<!-- '.$name.' -->', $line);
        $suffix = new TextNode('<!-- /'.$name.' -->', $line);

        if ($node->hasNode('parent')) {
            $this->wrapExtendingBody($node, $prefix, $suffix);

            return $node;
        }

        $this->wrap($node, $prefix, $suffix);

        return $node;
    }

    /**
     * For templates that `extends` another, wrap the content of the
     * `body` block — the template's top-level body never runs.
     *
     * Twig stores each block as a `BodyNode` containing the
     * `BlockNode`; we iterate to find the BlockNode and replace its
     * body with the wrapped sequence.
     *
     * @param ModuleNode $node   the extending module
     * @param TextNode   $prefix the opening marker
     * @param TextNode   $suffix the closing marker
     */
    private function wrapExtendingBody(ModuleNode $node, TextNode $prefix, TextNode $suffix): void
    {
        $blocks = $node->getNode('blocks');
        if (!$blocks->hasNode('body')) {
            return;
        }
        foreach ($blocks->getNode('body') as $child) {
            if ($child instanceof BlockNode) {
                $this->wrap($child, $prefix, $suffix);

                return;
            }
        }
    }

    /**
     * Lowest priority — run after other visitors so the markers stay
     * the outermost layer of the compiled body.
     *
     * @return int the visitor priority
     */
    public function getPriority(): int
    {
        return 0;
    }

    /**
     * Replace `$node`'s `body` child with a sequence: prefix, original
     * body, suffix.
     *
     * @param Node     $node   the node whose body to wrap
     * @param TextNode $prefix the opening marker
     * @param TextNode $suffix the closing marker
     */
    private function wrap(Node $node, TextNode $prefix, TextNode $suffix): void
    {
        $body = $node->getNode('body');
        $wrapped = new Nodes([$prefix, $body, $suffix], $body->getTemplateLine());
        $node->setNode('body', $wrapped);
    }
}
