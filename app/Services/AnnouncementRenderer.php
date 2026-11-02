<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Renders a platform announcement body for ONE recipient: it substitutes the
 * supported personalisation placeholders, then renders the resulting Markdown
 * to SAFE HTML (raw HTML escaped, unsafe links disallowed), exactly matching
 * the rendering the announcement feature has always used.
 *
 * Centralising this here means the live preview (controller) and the actual
 * delivery (job) render identically — a preview is a true what-you-see-is-what-
 * you-send of the body — and the single, security-critical Markdown call lives
 * in one place.
 *
 * ## Supported placeholders
 *
 * These tokens may appear anywhere in the Markdown body and are replaced before
 * rendering, so they also work inside links, lists and emphasis:
 *
 *   - `{{name}}`          — the recipient's name.
 *   - `{{company}}`       — the recipient's Company name (empty for a user with
 *                           no owning Company, e.g. a preview sample).
 *   - `{{platform}}`      — the Platform name (`config('app.name')`).
 *   - `{{dashboard_url}}` — the absolute URL of the client dashboard home.
 *
 * Tokens are matched case-insensitively and tolerate inner whitespace
 * (`{{ name }}`). Substitution happens on the RAW Markdown before rendering, so
 * a value that itself contains Markdown-significant characters is neutralised
 * by the same `html_input => 'escape'` pass as the rest of the body — a
 * recipient's name can never inject markup.
 */
class AnnouncementRenderer
{
    /**
     * The placeholder tokens the compose UI advertises, in display order, each
     * with a short human description for the editor's help text. Keyed by the
     * token as the operator types it.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        '{{name}}' => "The recipient's name",
        '{{company}}' => "The recipient's company name",
        '{{platform}}' => 'The platform name',
        '{{dashboard_url}}' => 'A link to the client dashboard',
    ];

    /**
     * Render the given Markdown body to safe HTML, personalised for $recipient.
     */
    public function renderForUser(string $body, User $recipient): string
    {
        return $this->toSafeHtml(
            $this->substitute($body, $this->valuesForUser($recipient))
        );
    }

    /**
     * Render the given Markdown body to safe HTML using explicit placeholder
     * values. Used by the live preview, where there is no real recipient and
     * illustrative sample values stand in.
     *
     * @param  array{name?: string, company?: string, platform?: string, dashboard_url?: string}  $values
     */
    public function renderWithValues(string $body, array $values): string
    {
        return $this->toSafeHtml($this->substitute($body, $values));
    }

    /**
     * The concrete placeholder values for a real recipient.
     *
     * @return array<string, string>
     */
    private function valuesForUser(User $recipient): array
    {
        return [
            'name' => $recipient->name,
            'company' => $recipient->company?->name ?? '',
            'platform' => (string) config('app.name'),
            'dashboard_url' => route('dashboard.index'),
        ];
    }

    /**
     * Replace every supported token in $body with its value. Matching is
     * case-insensitive and tolerant of inner whitespace, so `{{ Name }}` and
     * `{{name}}` both resolve. Unknown tokens are left untouched.
     *
     * @param  array<string, string>  $values
     */
    private function substitute(string $body, array $values): string
    {
        foreach ($values as $key => $value) {
            $body = (string) preg_replace(
                '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/i',
                // Escape backreference markers so a value containing `$1`/`\1`
                // is inserted literally, never interpreted by preg_replace.
                addcslashes($value, '\\$'),
                $body
            );
        }

        return $body;
    }

    /**
     * The single, security-critical Markdown render: raw HTML is escaped and
     * unsafe link schemes are rejected, so no untrusted markup or javascript:
     * URL can reach a recipient's inbox.
     */
    private function toSafeHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
