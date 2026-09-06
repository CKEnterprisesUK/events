<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin management of the Trust & Legal Centre: the Platform-level
 * policies of Events by CK Enterprises UK (Terms & Conditions, Privacy Notice,
 * PCI DSS statement, cookie policy, and any others).
 *
 * A Super_Admin authors each document's title and body (Markdown), controls
 * publication, and can add further documents. The well-known defaults are
 * ensured to exist as drafts so the standard set is always available to edit.
 * Published documents are exposed publicly at `/trust` by {@see \App\Http\Controllers\TrustController}.
 *
 * This surface is not tenant-scoped — a Super_Admin operates across the whole
 * Platform. It lives under the `/admin` group guarded by `super.admin`.
 */
class LegalDocumentController extends Controller
{
    /**
     * List every legal document (ensuring the defaults exist) for management.
     */
    public function index(): View
    {
        return view('admin.legal.index', [
            'documents' => LegalDocument::forManagement(),
        ]);
    }

    /**
     * Show the create form for a brand-new custom document.
     */
    public function create(): View
    {
        return view('admin.legal.create');
    }

    /**
     * Persist a new custom document. The slug is required and must be unique;
     * it is normalised to a URL-safe form.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/', 'unique:legal_documents,slug'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        LegalDocument::create([
            'slug' => Str::lower($validated['slug']),
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()
            ->route('admin.legal.index')
            ->with('status', __('Document ":title" created.', ['title' => $validated['title']]));
    }

    /**
     * Show the edit form for a single document.
     */
    public function edit(LegalDocument $legalDocument): View
    {
        return view('admin.legal.edit', [
            'document' => $legalDocument,
        ]);
    }

    /**
     * Update a document's title, body, publication state and ordering. The slug
     * is stable and not editable here (public URLs must not silently break).
     */
    public function update(Request $request, LegalDocument $legalDocument): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $legalDocument->update([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => $validated['sort_order'] ?? $legalDocument->sort_order,
        ]);

        return redirect()
            ->route('admin.legal.index')
            ->with('status', __('Document ":title" saved.', ['title' => $legalDocument->title]));
    }
}
