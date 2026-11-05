<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\HelpCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin management of the Knowledge Base sections (`help_categories`):
 * the top-level groupings of the public Help centre (Getting started, Events &
 * tickets, Payments & payouts, …).
 *
 * A Super_Admin authors each section's title and summary, controls publication
 * and ordering, and adds further sections. The individual articles within a
 * section are managed by {@see HelpArticleController}. Published sections (with
 * at least one published, non-empty article) are exposed publicly at `/help` by
 * {@see \App\Http\Controllers\KnowledgeBaseController} and in the in-dashboard
 * Help portal.
 *
 * Not tenant-scoped — a Super_Admin operates across the whole Platform. Lives
 * under the `/admin` group guarded by `super.admin`. Mirrors the Trust & Legal
 * Centre's {@see LegalDocumentController}.
 */
class HelpCategoryController extends Controller
{
    /**
     * List every section (with article counts) for management.
     */
    public function index(): View
    {
        return view('admin.help.index', [
            'categories' => HelpCategory::forManagement(),
        ]);
    }

    /**
     * Show the create form for a brand-new section.
     */
    public function create(): View
    {
        return view('admin.help.categories.create');
    }

    /**
     * Persist a new section. The slug is required, unique and URL-safe.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/', 'unique:help_categories,slug'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $category = HelpCategory::create([
            'slug' => Str::lower($validated['slug']),
            'title' => $validated['title'],
            'summary' => $validated['summary'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()
            ->route('admin.help.show', $category)
            ->with('status', __('Section ":title" created. Add some articles to it below.', ['title' => $category->title]));
    }

    /**
     * Show one section with its articles (the per-section manage screen).
     */
    public function show(HelpCategory $helpCategory): View
    {
        $helpCategory->load(['articles']);

        return view('admin.help.categories.show', [
            'category' => $helpCategory,
            'articles' => $helpCategory->articles,
        ]);
    }

    /**
     * Show the edit form for a section.
     */
    public function edit(HelpCategory $helpCategory): View
    {
        return view('admin.help.categories.edit', [
            'category' => $helpCategory,
        ]);
    }

    /**
     * Update a section's title, summary, publication state and ordering. The
     * slug is stable and not editable here (public URLs must not silently
     * break).
     */
    public function update(Request $request, HelpCategory $helpCategory): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $helpCategory->update([
            'title' => $validated['title'],
            'summary' => $validated['summary'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => $validated['sort_order'] ?? $helpCategory->sort_order,
        ]);

        return redirect()
            ->route('admin.help.show', $helpCategory)
            ->with('status', __('Section ":title" saved.', ['title' => $helpCategory->title]));
    }

    /**
     * Delete a section and all its articles (cascade). Confirmed in the UI.
     */
    public function destroy(HelpCategory $helpCategory): RedirectResponse
    {
        $title = $helpCategory->title;
        $helpCategory->delete();

        return redirect()
            ->route('admin.help.index')
            ->with('status', __('Section ":title" and its articles were deleted.', ['title' => $title]));
    }
}
