<?php

declare(strict_types=1);

namespace App\Mail;

use League\CommonMark\CommonMarkConverter;

/**
 * Turn an admin-editable Markdown email template into the
 * subject + html + text triple the mailer wants.
 *
 * The subject is plain text with `%token%` placeholders. The
 * body is Markdown with `%token%` placeholders; placeholders
 * are interpolated *before* Markdown conversion so values that
 * happen to contain Markdown-significant characters don't slip
 * through unescaped.
 *
 * Token values are accepted as plain strings — the renderer
 * does no Markdown escaping of values, since the admin
 * controls both the template and the value sources. If you ever
 * surface tokens with user-controlled content, escape at the
 * call site.
 */
final class EmailTemplateRenderer
{
    public function __construct(private readonly CommonMarkConverter $converter)
    {
    }

    /**
     * Render a Markdown template into the mailer's three slots.
     *
     * Substitutes every `%token%` occurrence in `$subjectTemplate`
     * and `$bodyTemplate` with the corresponding entry from
     * `$tokens`, then runs the body through CommonMark to produce
     * the HTML variant. The text variant is the post-interpolation
     * Markdown source verbatim — readable as plain text in a
     * non-HTML mail client.
     *
     * @param string                $subjectTemplate raw subject line with optional `%token%` placeholders
     * @param string                $bodyTemplate    raw Markdown body with optional `%token%` placeholders
     * @param array<string, string> $tokens          placeholder name (without the surrounding `%`) → replacement
     *
     * @return RenderedEmail the resolved subject, HTML body, and plain-text body
     */
    public function render(string $subjectTemplate, string $bodyTemplate, array $tokens): RenderedEmail
    {
        $subject = $this->substitute($subjectTemplate, $tokens);
        $bodyMarkdown = $this->substitute($bodyTemplate, $tokens);
        $bodyHtml = $this->converter->convert($bodyMarkdown)->getContent();

        return new RenderedEmail($subject, $bodyHtml, $bodyMarkdown);
    }

    /**
     * Replace every `%token%` occurrence with its mapped value.
     *
     * Tokens that don't appear in `$tokens` are left untouched
     * (so a missing token shows up as `%token_name%` in the
     * delivered mail — visibly broken, easy to spot).
     *
     * @param string                $template raw template text
     * @param array<string, string> $tokens   token name (without surrounding `%`) → replacement
     *
     * @return string the template with every known token replaced
     */
    private function substitute(string $template, array $tokens): string
    {
        if ([] === $tokens) {
            return $template;
        }

        $pairs = [];
        foreach ($tokens as $name => $value) {
            $pairs['%'.$name.'%'] = $value;
        }

        return strtr($template, $pairs);
    }
}
