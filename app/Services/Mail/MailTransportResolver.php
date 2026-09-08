<?php

namespace App\Services\Mail;

use App\Models\PlatformSetting;
use App\Services\Mail\Graph\GraphMailConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Single source of truth for "which mailer should the app send through right
 * now?". The choice is the Super_Admin's persisted `mail_transport` setting,
 * gated by whether the selected transport is actually usable:
 *
 *   - `graph` is only honoured when the Microsoft Graph credentials are fully
 *     present in the environment ({@see GraphMailConfig::isConfigured()}). If a
 *     Super_Admin selects Graph but the environment is not yet configured, the
 *     app safely stays on SMTP rather than dropping mail.
 *   - anything else resolves to the built-in `smtp` mailer (today's behaviour).
 *
 * Both the {@see \App\Providers\AppServiceProvider} (which sets the effective
 * default mailer per request) and the Settings admin screen (which shows the
 * operator what is actually in effect) ask this class, so the UI can never
 * disagree with what is really sending.
 *
 * The persisted setting is read through a very short-lived cache so this does
 * not add a DB query to every request while still reflecting a toggle change
 * almost immediately.
 */
class MailTransportResolver
{
    /**
     * Cache key + TTL (seconds) for the persisted transport choice. Short so an
     * operator flipping the toggle sees it take effect within a few seconds.
     */
    private const CACHE_KEY = 'mail_transport:selected';

    private const CACHE_TTL = 10;

    public function __construct(
        private readonly Cache $cache,
        private readonly GraphMailConfig $graphConfig,
    ) {}

    /**
     * The mailer name to use as `mail.default`: `graph` when selected AND
     * configured, otherwise `smtp`.
     */
    public function activeMailer(): string
    {
        if ($this->selected() === PlatformSetting::MAIL_TRANSPORT_GRAPH && $this->graphConfig->isConfigured()) {
            return PlatformSetting::MAIL_TRANSPORT_GRAPH;
        }

        return PlatformSetting::MAIL_TRANSPORT_SMTP;
    }

    /**
     * The transport the operator has selected (regardless of whether it is
     * currently usable). Used by the admin screen to explain the effective
     * state, e.g. "Graph selected but not configured — sending via SMTP".
     */
    public function selectedTransport(): string
    {
        return $this->selected();
    }

    /**
     * Whether Graph is fully configured in the environment. Surfaced on the
     * admin screen so the operator knows the toggle will actually take effect.
     */
    public function graphConfigured(): bool
    {
        return $this->graphConfig->isConfigured();
    }

    /**
     * Forget the cached selection so a just-saved toggle change is observed on
     * the next send/read without waiting for the TTL to lapse.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * The persisted selection, briefly cached. Defensive against the settings
     * row/column not existing yet (e.g. before the migration is applied): any
     * failure falls back to the SMTP default so mail never breaks.
     */
    private function selected(): string
    {
        try {
            return (string) $this->cache->remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                fn (): string => PlatformSetting::current()->mail_transport
                    ?? PlatformSetting::DEFAULT_MAIL_TRANSPORT
            );
        } catch (Throwable) {
            return PlatformSetting::DEFAULT_MAIL_TRANSPORT;
        }
    }
}
