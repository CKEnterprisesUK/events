<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Shared audit-log filtering used by both the organiser Activity surface
 * ({@see \App\Http\Controllers\AuditLogController}) and the super-admin Audit
 * surface ({@see \App\Http\Controllers\SuperAdmin\AuditController}), so both
 * filter identically. Each controller owns its own SCOPING (organiser: explicit
 * `company_id`; super-admin: cross-tenant); only the category/action/date/text
 * filtering lives here.
 */
trait FiltersAuditLogs
{
    /**
     * Normalise the shared request filters to a stable shape the view echoes.
     *
     * @return array{category: string, action: string, from: string, to: string, q: string}
     */
    protected function auditFilters(Request $request): array
    {
        return [
            'category' => (string) $request->query('category', ''),
            'action' => (string) $request->query('action', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'q' => trim((string) $request->query('q', '')),
        ];
    }

    /**
     * Apply the shared filter set to a query builder.
     *
     * @param  array{category: string, action: string, from: string, to: string, q: string}  $filters
     */
    protected function applyAuditFilters(Builder $query, array $filters): void
    {
        if ($filters['category'] !== '' && isset(AuditLog::CATEGORY_LABELS[$filters['category']])) {
            $actions = array_keys(array_filter(
                AuditLog::ACTIONS,
                fn (array $meta) => $meta['category'] === $filters['category'],
            ));
            $query->whereIn('action', $actions);
        }

        if ($filters['action'] !== '' && isset(AuditLog::ACTIONS[$filters['action']])) {
            $query->where('action', $filters['action']);
        }

        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('summary', 'like', $like)
                    ->orWhere('actor_label', 'like', $like);
            });
        }
    }

    /**
     * Action key => human label, for the action filter dropdown.
     *
     * @return array<string, string>
     */
    protected function auditActionOptions(): array
    {
        $options = [];

        foreach (AuditLog::ACTIONS as $key => $meta) {
            $options[$key] = $meta['label'];
        }

        return $options;
    }
}
