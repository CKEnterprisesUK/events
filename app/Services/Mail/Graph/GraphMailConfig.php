<?php

namespace App\Services\Mail\Graph;

/**
 * Immutable, validated snapshot of the Microsoft Graph mail configuration
 * (read from `config('services.graph')`). Keeping the config behind a small
 * value object lets every collaborator — the {@see GraphMailer} that fetches
 * tokens and calls `sendMail`, and the transport-selection logic in the service
 * provider — ask the same question ("is Graph usable?") in one place via
 * {@see self::isConfigured()}.
 *
 * The credentials themselves live only in the environment/config; the platform
 * `mail_transport` toggle merely records the operator's *choice*. Graph is only
 * actually used when the choice is `graph` AND this config is complete, so a
 * half-configured environment can never silently drop outbound mail.
 */
final class GraphMailConfig
{
    public function __construct(
        public readonly ?string $tenantId,
        public readonly ?string $clientId,
        public readonly ?string $clientSecret,
        public readonly ?string $from,
        public readonly bool $saveToSentItems,
        public readonly string $authority,
        public readonly string $baseUri,
        public readonly int $timeout,
    ) {}

    /**
     * Build from the `services.graph` config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            tenantId: self::str($config['tenant_id'] ?? null),
            clientId: self::str($config['client_id'] ?? null),
            clientSecret: self::str($config['client_secret'] ?? null),
            from: self::str($config['from'] ?? null),
            saveToSentItems: (bool) ($config['save_to_sent_items'] ?? true),
            authority: rtrim((string) ($config['authority'] ?? 'https://login.microsoftonline.com'), '/'),
            baseUri: rtrim((string) ($config['base_uri'] ?? 'https://graph.microsoft.com'), '/'),
            timeout: (int) ($config['timeout'] ?? 15),
        );
    }

    /**
     * True only when every credential needed to authenticate and send is
     * present. When false the app must not attempt Graph and should fall back
     * to SMTP even if the operator selected Graph.
     */
    public function isConfigured(): bool
    {
        return $this->tenantId !== null
            && $this->clientId !== null
            && $this->clientSecret !== null
            && $this->from !== null;
    }

    /**
     * The OAuth2 client-credentials token endpoint for this tenant.
     */
    public function tokenUrl(): string
    {
        return $this->authority.'/'.$this->tenantId.'/oauth2/v2.0/token';
    }

    /**
     * The Graph `sendMail` endpoint for the configured sending mailbox.
     */
    public function sendMailUrl(): string
    {
        return $this->baseUri.'/v1.0/users/'.rawurlencode((string) $this->from).'/sendMail';
    }

    /**
     * Normalise a config value to a non-empty trimmed string, or null.
     */
    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
