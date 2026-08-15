<?php

declare(strict_types=1);

namespace App\Services\Configuration;

/**
 * NotificationTemplateContentPolicyService — validates notification
 * template CONTENT. Read-only analysis; it never writes a template.
 *
 * WHAT IT CAN AND CANNOT CHECK, AND WHY THAT SPLIT IS HONEST
 * ----------------------------------------------------------
 * Mission section 70 asks for three things: reject unknown variables,
 * reject malformed variable syntax, and guarantee template content can
 * never execute code. Only two of those are answerable today.
 *
 *   MALFORMED SYNTAX — checkable. A `{{` with no closing `}}`, an
 *   empty placeholder, or a placeholder containing characters that
 *   could not be an identifier are all decidable from the text alone.
 *
 *   EXECUTABLE CONTENT — checkable, and the most important of the
 *   three. Refusing Blade directives, raw-echo syntax, PHP tags and
 *   script tags does not require knowing anything about the variable
 *   vocabulary.
 *
 *   UNKNOWN VARIABLES — NOT checkable. This codebase has no canonical
 *   variable registry: no allowlist, no per-event variable catalog, and
 *   in fact no renderer at all (NotificationDispatchService never
 *   interpolates a body; DispatchNotificationJob explicitly performs no
 *   transport call). Without a registry there is no such thing as an
 *   "unknown" variable, and inventing an allowlist here would fabricate
 *   a governance capability while silently breaking any template using
 *   a variable the invented list omitted. So this service EXTRACTS and
 *   REPORTS the variables a template uses, and the gap is disclosed
 *   rather than papered over (mission section 100).
 *
 * WHY EXECUTABLE-CONTENT REJECTION STILL MATTERS TODAY
 * ----------------------------------------------------
 * Nothing renders these bodies right now, so no directive in a stored
 * template can execute today. That is exactly why this is the right
 * moment to enforce it: the check costs nothing while the table is
 * small, and it means whoever eventually wires a real renderer cannot
 * inherit a corpus of stored templates that already contains Blade or
 * PHP. Refusing the content at write time is a durable guarantee;
 * refusing it at render time would depend on a renderer nobody has
 * written yet.
 */
class NotificationTemplateContentPolicyService
{
    /**
     * Constructs that must never appear in template content. Each entry
     * is [pattern, operator-facing explanation].
     */
    private const FORBIDDEN_PATTERNS = [
        ['/<\?(?:php|=)/i', 'PHP open tags'],
        ['/\{\{--/', 'Blade comment syntax'],
        ['/\{!!/', 'Blade unescaped-output syntax ({!! !!}), which would render raw HTML'],
        ['/@(?:if|else|elseif|endif|foreach|endforeach|for|endfor|while|endwhile|php|endphp|include|extends|section|yield|component|inject|eval|csrf)\b/i', 'Blade directives'],
        ['/<script\b/i', 'script tags'],
        ['/\bjavascript\s*:/i', 'javascript: URLs'],
        ['/\{\{\s*[^}]*\b(?:eval|exec|shell_exec|system|passthru|popen|proc_open|file_get_contents|unlink)\s*\(/i', 'function calls inside a placeholder'],
    ];

    /**
     * A placeholder name we are willing to treat as well-formed: a
     * simple identifier, optionally dotted (e.g. `client.first_name`).
     * Deliberately conservative — anything more expressive starts to
     * resemble an expression language, which mission section 8 forbids
     * introducing.
     */
    private const VALID_VARIABLE_NAME = '/^[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)*$/i';

    /**
     * Every `{{ ... }}` placeholder in the content, in order of first
     * appearance, deduplicated and trimmed.
     *
     * @return list<string>
     */
    public function extractVariables(?string $content): array
    {
        if (! is_string($content) || $content === '') {
            return [];
        }

        preg_match_all('/\{\{(.*?)\}\}/s', $content, $matches);

        $variables = [];

        foreach ($matches[1] ?? [] as $raw) {
            $name = trim($raw);

            if ($name !== '' && ! in_array($name, $variables, true)) {
                $variables[] = $name;
            }
        }

        return $variables;
    }

    /**
     * Validation errors for a template's subject + body. An empty array
     * means the content is acceptable.
     *
     * @return list<string>
     */
    public function validate(?string $subject, ?string $body): array
    {
        $errors = [];

        foreach (['Subject' => $subject, 'Body' => $body] as $label => $content) {
            if (! is_string($content) || $content === '') {
                continue;
            }

            foreach (self::FORBIDDEN_PATTERNS as [$pattern, $description]) {
                if (preg_match($pattern, $content) === 1) {
                    $errors[] = sprintf(
                        '%s contains %s. Notification templates are content, never executable code.',
                        $label,
                        $description,
                    );
                }
            }

            foreach ($this->malformedPlaceholders($content) as $problem) {
                $errors[] = $label.' '.$problem;
            }
        }

        return array_values(array_unique($errors));
    }

    public function isValid(?string $subject, ?string $body): bool
    {
        return $this->validate($subject, $body) === [];
    }

    /**
     * Whether unknown-variable checking is available. Always false
     * today, and surfaced so the console can say so plainly instead of
     * implying variables were verified against something.
     */
    public function variableRegistryAvailable(): bool
    {
        return false;
    }

    public function variableRegistryStatus(): string
    {
        return 'Not implemented — this codebase has no canonical notification variable registry, '
            .'so variable NAMES cannot be validated against an approved vocabulary. Syntax and '
            .'executable-content checks still apply.';
    }

    /**
     * @return list<string>
     */
    private function malformedPlaceholders(string $content): array
    {
        $problems = [];

        // Unbalanced delimiters: every `{{` needs a matching `}}`.
        if (substr_count($content, '{{') !== substr_count($content, '}}')) {
            $problems[] = 'has an unclosed variable placeholder — every "{{" needs a matching "}}".';
        }

        preg_match_all('/\{\{(.*?)\}\}/s', $content, $matches);

        foreach ($matches[1] ?? [] as $raw) {
            $name = trim($raw);

            if ($name === '') {
                $problems[] = 'contains an empty variable placeholder ("{{ }}").';

                continue;
            }

            if (preg_match(self::VALID_VARIABLE_NAME, $name) !== 1) {
                $problems[] = sprintf(
                    'contains a malformed variable placeholder "{{ %s }}" — use a simple name such as {{ client_name }}.',
                    $name,
                );
            }
        }

        return $problems;
    }
}
