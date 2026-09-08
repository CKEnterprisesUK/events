<?php

namespace App\Services\Mail\Graph;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;

/**
 * Thin client over the two Microsoft Graph calls the mail transport needs:
 * acquiring an application (client-credentials) access token, and POSTing a
 * `sendMail` request as the configured mailbox.
 *
 * Authentication uses the OAuth2 client-credentials grant against the tenant's
 * token endpoint with the `https://graph.microsoft.com/.default` scope — the
 * app authenticates as itself (no signed-in user), which requires the
 * `Mail.Send` *application* permission granted with admin consent on the Azure
 * AD app registration. Tokens are cached until shortly before they expire so we
 * are not fetching a fresh token on every email.
 *
 * This client deals only in already-built Graph JSON payloads; converting a
 * Symfony {@see \Symfony\Component\Mime\Email} into that payload is the
 * transport's job, keeping the HTTP concern isolated and easy to test.
 */
class GraphMailClient
{
    /**
     * Cache key for the shared application access token. Keyed by tenant+client
     * so rotating credentials or switching tenants does not reuse a stale token.
     */
    private const TOKEN_CACHE_PREFIX = 'graph_mail:token:';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly GraphMailConfig $config,
    ) {}

    /**
     * Send one already-composed Graph message payload via the configured
     * mailbox. Throws on any non-success response so the caller (the transport,
     * and ultimately the test-email tool or the queued job) surfaces the failure
     * rather than silently dropping the email.
     *
     * @param  array<string, mixed>  $message  the Graph `message` resource.
     */
    public function sendMail(array $message): void
    {
        $token = $this->accessToken();

        $response = $this->http
            ->withToken($token)
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->post($this->config->sendMailUrl(), [
                'message' => $message,
                'saveToSentItems' => $this->config->saveToSentItems,
            ]);

        // Graph returns 202 Accepted on success. Anything else is an error we
        // must not swallow — bubble up the status and Graph's error body.
        if (! $response->successful()) {
            $body = $response->json();

            throw new GraphMailException(
                message: sprintf(
                    'Microsoft Graph sendMail failed (HTTP %d): %s',
                    $response->status(),
                    $this->errorMessage($body)
                ),
                status: $response->status(),
                graphCode: $this->errorCode($body),
                stage: 'send',
            );
        }
    }

    /**
     * Live, read-only probe used by the Super_Admin diagnostics screen: fetch a
     * fresh application token (bypassing the cache so it is a genuine check) and
     * return true, or throw a {@see GraphMailException} tagged with the `token`
     * stage. Proves the tenant/client id + secret and admin consent are in place
     * without sending any mail.
     */
    public function fetchTokenForDiagnostics(): bool
    {
        $this->requestToken();

        return true;
    }

    /**
     * Live, read-only probe that fetches a fresh token and decodes its `roles`
     * claim — the application permissions Azure actually put on the token. This
     * is the decisive check for a 403-after-successful-auth: if `Mail.Send` is
     * absent here, admin consent is not effective no matter what the portal
     * appears to show. Returns the granted role list (may be empty). Throws a
     * `token`-stage {@see GraphMailException} if authentication itself fails.
     *
     * @return list<string>
     */
    public function tokenRolesForDiagnostics(): array
    {
        [$token] = $this->requestToken();

        return $this->decodeTokenRoles($token);
    }

    /**
     * Decode the `roles` claim from a JWT access token without verifying its
     * signature (we only trust it for display — Graph itself enforces the token
     * on every call). Safe against malformed tokens: returns an empty list.
     *
     * @return list<string>
     */
    private function decodeTokenRoles(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payload === false) {
            return [];
        }

        $claims = json_decode($payload, true);

        if (! is_array($claims) || ! isset($claims['roles']) || ! is_array($claims['roles'])) {
            return [];
        }

        return array_values(array_filter($claims['roles'], 'is_string'));
    }

    /**
     * Return a valid application access token, fetching and caching a new one
     * when the cache is empty or the previous token is near expiry.
     */
    private function accessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();

        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = $this->requestToken();

        // Cache slightly short of the real expiry (60s safety margin) so a token
        // never expires mid-flight between the cache read and the API call.
        $ttl = max(60, $expiresIn - 60);
        $this->cache->put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * Discard any cached application token so the next send/probe fetches a
     * fresh one. Needed after an Azure change (e.g. granting admin consent or
     * rotating the secret): a token issued *before* the change stays valid in
     * cache for up to ~an hour and would keep reflecting the old state. Exposed
     * so a Super_Admin can flush it from the Settings screen without shell
     * access (the app runs on cPanel with no SSH).
     */
    public function forgetCachedToken(): void
    {
        $this->cache->forget($this->tokenCacheKey());
    }

    /**
     * The cache key for the shared application token, keyed by tenant+client so
     * rotating credentials or switching tenants never reuses a stale token.
     */
    private function tokenCacheKey(): string
    {
        return self::TOKEN_CACHE_PREFIX.md5($this->config->tenantId.'|'.$this->config->clientId);
    }

    /**
     * Perform the client-credentials token request against the tenant endpoint.
     * Shared by the cached {@see self::accessToken()} path and the uncached
     * diagnostics probe. Throws a `token`-stage {@see GraphMailException} on any
     * failure so callers can distinguish an auth problem from a send problem.
     *
     * @return array{0: string, 1: int}  the access token and its lifetime (s).
     */
    private function requestToken(): array
    {
        $response = $this->http
            ->asForm()
            ->timeout($this->config->timeout)
            ->acceptJson()
            ->post($this->config->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->clientId,
                'client_secret' => $this->config->clientSecret,
                'scope' => $this->config->baseUri.'/.default',
            ]);

        if (! $response->successful()) {
            $body = $response->json();

            throw new GraphMailException(
                message: sprintf(
                    'Microsoft Graph token request failed (HTTP %d): %s',
                    $response->status(),
                    $this->errorMessage($body)
                ),
                status: $response->status(),
                graphCode: $this->errorCode($body),
                stage: 'token',
            );
        }

        $token = (string) $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($token === '') {
            throw new GraphMailException(
                message: 'Microsoft Graph token response contained no access_token.',
                status: $response->status(),
                stage: 'token',
            );
        }

        return [$token, $expiresIn];
    }

    /**
     * Pull a human-readable message out of a Graph/OAuth error body, which may
     * be shaped as `{"error":{"message":...}}` (Graph) or
     * `{"error_description":...}` (token endpoint).
     *
     * @param  mixed  $body
     */
    private function errorMessage(mixed $body): string
    {
        if (is_array($body)) {
            if (isset($body['error']['message']) && is_string($body['error']['message'])) {
                return $body['error']['message'];
            }

            if (isset($body['error_description']) && is_string($body['error_description'])) {
                return $body['error_description'];
            }

            if (isset($body['error']) && is_string($body['error'])) {
                return $body['error'];
            }
        }

        return 'unknown error';
    }

    /**
     * Pull the machine-readable error code out of a Graph error body
     * (`{"error":{"code":...}}`) when present, for display/logging alongside
     * the message. Null when absent.
     *
     * @param  mixed  $body
     */
    private function errorCode(mixed $body): ?string
    {
        if (is_array($body) && isset($body['error']['code']) && is_string($body['error']['code'])) {
            return $body['error']['code'];
        }

        return null;
    }
}
