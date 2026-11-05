<?php

namespace App\Support;

/**
 * Small holder for Help & support constants shared across the app.
 *
 * The knowledge base content that this class used to hold in code has been
 * migrated into the database (`help_categories` / `help_articles`), where a
 * Super_Admin authors it and it is published to both the public Help centre
 * ({@see \App\Http\Controllers\KnowledgeBaseController} at `/help`) and the
 * in-dashboard Help portal ({@see \App\Http\Controllers\HelpController}). The
 * default content was seeded from {@see \Database\Seeders\HelpContentSeeder}.
 *
 * Only the public support mailbox remains here, as it is a fixed platform
 * contact rather than editable content.
 */
final class HelpCentre
{
    /**
     * The public support mailbox shown as the fallback contact channel.
     */
    public const PLATFORM_SUPPORT_EMAIL = 'support@ckenterprises.co.uk';
}
