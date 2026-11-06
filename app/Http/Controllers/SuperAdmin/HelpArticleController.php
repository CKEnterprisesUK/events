<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Super-admin management of the Knowledge Base articles (`help_articles`) within
 * a section. A Super_Admin authors each article's title and body (Markdown),
 * controls publication and ordering, and can add or remove articles. Published,
 * non-empty articles are exposed publicly at `/help/{category}/{article}` by
 * {@see \App\Http\Controllers\KnowledgeBaseController} and in the in-dashboard
 * Help portal.
 *
 * Articles are always addressed through their owning {@see HelpCategory} (a
 * nested resource) so the slug need only be unique within the section and the
 * public URL stays readable. Not tenant-scoped; lives under the `/admin` group
 * guarded by `super.admin`. Mirrors the Trust & Legal Centre's
 * {@see LegalDocumentController}.
 */
class HelpArticleController extends Controller
{
    /**
     * Show the create form for a new article in a section.
     */
    public function create(HelpCategory $helpCategory): View
    {
        return view('admin.help.articles.create', [
            'category' => $helpCategory,
        ]);
    }

    /**
     * Persist a new article under the section. The slug is required and must be
     * unique within the section; it is normalised to a URL-safe form.
     */
    public function store(Request $request, HelpCategory $helpCategory): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => [
                'required', 'string', 'max:150', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('help_articles', 'slug')->where('help_category_id', $helpCategory->getKey()),
            ],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $helpCategory->articles()->create([
            'slug' => Str::lower($validated['slug']),
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return redirect()
            ->route('admin.help.show', $helpCategory)
            ->with('status', __('Article ":title" created.', ['title' => $validated['title']]));
    }

    /**
     * Show the edit form for a single article. The article must belong to the
     * section in the URL (prevents cross-section tampering).
     */
    public function edit(HelpCategory $helpCategory, HelpArticle $helpArticle): View
    {
        $this->ensureArticleBelongsToCategory($helpCategory, $helpArticle);

        return view('admin.help.articles.edit', [
            'category' => $helpCategory,
            'article' => $helpArticle,
        ]);
    }

    /**
     * Update an article's title, body, publication state and ordering. The slug
     * is stable and not editable here (public URLs must not silently break).
     */
    public function update(Request $request, HelpCategory $helpCategory, HelpArticle $helpArticle): RedirectResponse
    {
        $this->ensureArticleBelongsToCategory($helpCategory, $helpArticle);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'is_published' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $helpArticle->update([
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'is_published' => $request->boolean('is_published'),
            'sort_order' => $validated['sort_order'] ?? $helpArticle->sort_order,
        ]);

        return redirect()
            ->route('admin.help.show', $helpCategory)
            ->with('status', __('Article ":title" saved.', ['title' => $helpArticle->title]));
    }

    /**
     * Delete an article. Confirmed in the UI.
     */
    public function destroy(HelpCategory $helpCategory, HelpArticle $helpArticle): RedirectResponse
    {
        $this->ensureArticleBelongsToCategory($helpCategory, $helpArticle);

        $title = $helpArticle->title;
        $helpArticle->delete();

        return redirect()
            ->route('admin.help.show', $helpCategory)
            ->with('status', __('Article ":title" was deleted.', ['title' => $title]));
    }

    /**
     * Guard that the article is owned by the section in the URL, so the nested
     * route cannot be used to edit an article from another section.
     */
    private function ensureArticleBelongsToCategory(HelpCategory $helpCategory, HelpArticle $helpArticle): void
    {
        abort_unless($helpArticle->help_category_id === $helpCategory->getKey(), 404);
    }
}
