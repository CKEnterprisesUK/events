<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ReservedSlug;
use App\Rules\CompanySlug;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Super-admin management of the Company_Slug blocklist: the slugs that may never
 * be claimed by a Company at self-signup or on a slug change.
 *
 * Path-based tenancy routes every storefront at `/{company-slug}/...`, so a slug
 * that collides with a reserved platform route/infra path would produce an
 * unreachable/confusing storefront; the list also holds brand/abuse words the
 * Platform declines to hand out. The blocklist is enforced by
 * {@see \App\Rules\CompanySlug}.
 *
 * Seeded system rows ({@see ReservedSlug::$is_system}) are surfaced but cannot
 * be removed (deleting one would let a storefront shadow a real route); operator
 * additions are freely removable.
 *
 * This surface is not tenant-scoped — a Super_Admin operates across the whole
 * Platform. It lives under the `/admin` group guarded by `super.admin`.
 */
class ReservedSlugController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * List every reserved slug (ensuring the system defaults exist) for
     * management.
     */
    public function index(): View
    {
        return view('admin.reserved-slugs.index', [
            'slugs' => ReservedSlug::forManagement(),
        ]);
    }

    /**
     * Add a new operator-defined reserved slug. The value must be a
     * syntactically valid slug (same format as a Company_Slug) and not already
     * on the list. New rows are never `is_system` — only the seed creates those.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $slug = ReservedSlug::normalise($validated['slug']);

        // Enforce the same syntactic shape a Company_Slug must take, so the
        // blocklist can only contain values that could actually be requested.
        if (! CompanySlug::isValidFormat($slug)) {
            throw ValidationException::withMessages([
                'slug' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            ]);
        }

        if (ReservedSlug::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'That slug is already reserved.',
            ]);
        }

        ReservedSlug::create([
            'slug' => $slug,
            'reason' => $validated['reason'] ?? null,
            'is_system' => false,
        ]);

        // Platform-level change (no tenant): recorded with a null company_id so
        // it appears only on the super-admin trail.
        $this->audit->record(
            action: AuditLog::RESERVED_SLUG_ADDED,
            summary: 'Reserved the slug "'.$slug.'"',
            context: ['slug' => $slug, 'reason' => $validated['reason'] ?? null],
        );

        return redirect()
            ->route('admin.reserved-slugs.index')
            ->with('status', __('":slug" is now reserved.', ['slug' => $slug]));
    }

    /**
     * Remove an operator-defined reserved slug. System rows are protected —
     * removing one would let a storefront shadow a real route — so a delete
     * against a system row is rejected.
     */
    public function destroy(ReservedSlug $reservedSlug): RedirectResponse
    {
        if ($reservedSlug->is_system) {
            throw ValidationException::withMessages([
                'slug' => 'System reserved slugs cannot be removed.',
            ]);
        }

        $slug = $reservedSlug->slug;
        $reservedSlug->delete();

        $this->audit->record(
            action: AuditLog::RESERVED_SLUG_REMOVED,
            summary: 'Un-reserved the slug "'.$slug.'"',
            context: ['slug' => $slug],
        );

        return redirect()
            ->route('admin.reserved-slugs.index')
            ->with('status', __('":slug" is no longer reserved.', ['slug' => $slug]));
    }
}
