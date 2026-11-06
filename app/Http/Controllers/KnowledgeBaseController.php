<?php

namespace App\Http\Controllers;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public Knowledge Base ("Help centre"): the self-service articles that
 * explain how the product works, published by a Super_Admin from the
 * `help_categories` / `help_articles` tables. This is the DB-backed,
 * publicly-accessible replacement for the former in-code `App\Support\HelpCentre`.
 *
 * Served at the reserved `/help` prefix (no tenant, no auth), declared before
 * the `/{company-slug}` storefront catch-all so it renders the Platform Help
 * centre rather than being treated as a storefront slug. Only published
 * categories/articles with content are exposed; unknown or unpublished slugs
 * 404 so drafts never leak to the public surface. Mirrors
 * {@see TrustController} for the Trust & Legal Centre.
 */
class KnowledgeBaseController extends Controller
{
    /**
     * The Knowledge Base hub: every published section and its published
     * articles.
     */
    public function index(): View
    {
        return view('help.index', [
            'categories' => HelpCategory::published(),
        ]);
    }

    /**
     * A single section: its published articles. Unknown/unpublished/empty
     * sections 404.
     */
    public function category(string $category): View
    {
        $section = $this->resolveCategory($category);

        return view('help.category', [
            'category' => $section,
            'articles' => $section->publishedArticles()->get(),
            'categories' => HelpCategory::published(),
        ]);
    }

    /**
     * A single published article within a section. Unknown or unpublished/empty
     * slugs (section or article) 404.
     */
    public function article(string $category, string $article): View
    {
        $section = $this->resolveCategory($category);

        $item = $section->publishedArticles()
            ->where('slug', $article)
            ->first();

        if ($item === null) {
            throw new NotFoundHttpException;
        }

        return view('help.article', [
            'category' => $section,
            'article' => $item,
            'related' => $section->publishedArticles()->get(),
            'categories' => HelpCategory::published(),
        ]);
    }

    /**
     * Resolve a published, non-empty category by slug or 404.
     */
    private function resolveCategory(string $slug): HelpCategory
    {
        $section = HelpCategory::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->whereHas('publishedArticles')
            ->first();

        if ($section === null) {
            throw new NotFoundHttpException;
        }

        return $section;
    }
}
