<?php

namespace App\Services\Mail\Graph;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

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
            throw new RuntimeException(sprintf(
                'Microsoft Graph sendMail failed (HTTP %d): %s',
                $response->status(),
                $this->errorMessage($response->json())
            ));
        }
    }

    /**
     * Return a valid application access token, fetching and caching a new one
     * when the cache is empty or the previous token is near expiry.
     */
    private function accessToken(): string
    {
        $cacheKey = self::TOKEN_CACHE_PREFIX.md5($this->config->tenantId.'|'.$this->config->clientId);

        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

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
            throw new RuntimeException(sprintf(
                'Microsoft Graph token request failed (HTTP %d): %s',
                $response->status(),
                $this->errorMessage($response->json())
            ));
        }

        $token = (string) $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 3600);

        if ($token === '') {
            throw new RuntimeException('Microsoft Graph token response contained no access_token.');
        }

        // Cache slightly short of the real expiry (60s safety margin) so a token
        // never expires mid-flight between the cache read and the API call.
        $ttl = max(60, $expiresIn - 60);
        $this->cache->put($cacheKey, $token, $ttl);

        return $token;
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
}
