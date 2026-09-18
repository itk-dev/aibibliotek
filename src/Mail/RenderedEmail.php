<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Output of {@see EmailTemplateRenderer::render()}.
 *
 * Carries the resolved subject line and the two body
 * representations the mailer composes from — a CommonMark-
 * rendered HTML variant and the plain-text Markdown source.
 * The mailer attaches both so HTML-capable clients see the
 * formatted version while plain-text clients see something
 * still legible.
 */
final readonly class RenderedEmail
{
    /**
     * @param string $subject  resolved subject line (no `%token%` placeholders left)
     * @param string $bodyHtml CommonMark-rendered HTML body
     * @param string $bodyText post-interpolation Markdown source, used verbatim as the plain-text variant
     */
    public function __construct(
        public string $subject,
        public string $bodyHtml,
        public string $bodyText,
    ) {
    }
}
