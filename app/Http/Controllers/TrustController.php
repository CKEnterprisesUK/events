<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public Trust & Legal Centre. Serves the Platform-level policies of Events
 * by CK Enterprises UK (Terms & Conditions, Privacy Notice, PCI DSS statement,
 * cookie policy, and any others) authored by a Super_Admin.
 *
 * Served at the reserved `/trust` prefix (no tenant, no auth), declared before
 * the `/{company-slug}` storefront catch-all so it renders the Platform centre
 * rather than being treated as a storefront slug. Only published documents with
 * content are exposed; unknown or unpublished slugs 404.
 */
class TrustController extends Controller
{
    /**
     * The Trust & Legal Centre hub: lists every published policy.
     */
    public function index(): View
    {
        return view('trust.index', [
            'documents' => LegalDocument::published(),
        ]);
    }

    /**
     * A single published policy. Unknown or unpublished/empty slugs 404 so
     * drafts never leak to the public surface.
     */
    public function show(string $slug): View
    {
        $document = LegalDocument::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->first();

        if ($document === null) {
            throw new NotFoundHttpException;
        }

        return view('trust.show', [
            'document' => $document,
            'documents' => LegalDocument::published(),
        ]);
    }
}
