<?php

namespace App\Services\Mail\Graph;

use Throwable;

/**
 * Super_Admin-facing, read-only diagnostics for the Microsoft Graph mail
 * transport. Surfaces a masked view of the configured credentials and runs a
 * live authentication probe, so an operator working through the Azure setup can
 * see exactly how far a request gets:
 *
 *   config → token → (ready to send)
 *
 * Nothing here sends mail or mutates state; it only reads config and performs
 * the OAuth2 token request. Secrets are never returned in full — the client id
 * and secret are masked to a short fingerprint.
 */
class GraphMailDiagnostics
{
    public function __construct(
        private readonly GraphMailConfig $config,
        private readonly GraphMailClient $client,
    ) {}

    /**
     * A masked, display-safe summary of the current Graph configuration for the
     * Settings screen. Presence flags let the operator confirm each value is set
     * without exposing secrets; the tenant id and sending mailbox are shown in
     * full because they are not sensitive and are the values most worth
     * eyeballing against Azure.
     *
     * @return array<string, string>
     */
    public function configSummary(): array
    {
        return [
            'configured' => $this->config->isConfigured() ? 'yes' : 'no',
            'tenant_id' => $this->config->tenantId ?? '—',
            'client_id' => $this->mask($this->config->clientId),
            'client_secret' => $this->config->clientSecret !== null ? 'set' : 'not set',
            'from' => $this->config->from ?? '—',
            'save_to_sent_items' => $this->config->saveToSentItems ? 'yes' : 'no',
            'token_url' => $this->config->isConfigured() ? $this->config->tokenUrl() : '—',
            'send_mail_url' => $this->config->isConfigured() ? $this->config->sendMailUrl() : '—',
        ];
    }

    /**
     * Run the live probe: verify config completeness, then attempt to acquire an
     * application token. Returns a structured result (never throws) so the
     * controller can flash a clear success/failure with a targeted hint.
     */
    public function probe(): GraphDiagnosticResult
    {
        if (! $this->config->isConfigured()) {
            return GraphDiagnosticResult::failure(
                stage: 'config',
                message: 'Microsoft Graph is not fully configured.',
                hint: 'Set GRAPH_MAIL_TENANT_ID, GRAPH_MAIL_CLIENT_ID, GRAPH_MAIL_CLIENT_SECRET and '
                    .'GRAPH_MAIL_FROM in the environment, then re-run diagnostics.',
            );
        }

        try {
            $this->client->fetchTokenForDiagnostics();
        } catch (GraphMailException $e) {
            return GraphDiagnosticResult::failure(
                stage: $e->stage,
                message: $e->getMessage(),
                hint: $e->hint(),
                status: $e->status,
            );
        } catch (Throwable $e) {
            // Network/DNS/TLS and other transport-level problems never reached
            // Graph — report them plainly rather than as an auth failure.
            return GraphDiagnosticResult::failure(
                stage: 'token',
                message: 'Could not reach Microsoft Graph: '.$e->getMessage(),
                hint: 'Check outbound network access to login.microsoftonline.com and graph.microsoft.com.',
            );
        }

        return GraphDiagnosticResult::ok(
            stage: 'token',
            message: 'Authenticated to Microsoft Graph successfully. The app registration and admin '
                .'consent are in place. If a test email still returns 403, the issue is mailbox '
                .'send permission (Application Access Policy) rather than authentication.',
        );
    }

    /**
     * Mask a credential to a short, non-reversible fingerprint (first/last few
     * characters) so the operator can tell whether the configured value matches
     * Azure without the full secret ever leaving the server.
     */
    private function mask(?string $value): string
    {
        if ($value === null) {
            return 'not set';
        }

        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($value, 0, 4).str_repeat('•', 6).substr($value, -4);
    }
}
