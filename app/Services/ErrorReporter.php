<?php

namespace App\Services;

use App\Models\ErrorReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists an uncaught server error to the {@see ErrorReport} table and returns
 * the stored row (carrying the short, customer-facing {@see ErrorReport::$reference}).
 *
 * This is only invoked in production (`APP_DEBUG=false`) from the exception
 * handler in bootstrap/app.php: rather than leak a stack trace to the visitor,
 * the raw diagnostic detail is stored server-side and the customer is shown the
 * reference to quote to support. Storing must never itself throw and mask the
 * original error, so the whole capture is wrapped and failures fall back to the
 * log channel.
 */
class ErrorReporter
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Store an error report for the given exception and (optional) request, and
     * return the persisted row. Returns null if capture itself failed — the
     * caller then shows a generic page with no reference.
     *
     * @param  int  $statusCode  the HTTP status served to the client (≈500).
     */
    public function capture(Throwable $e, ?Request $request, int $statusCode = 500): ?ErrorReport
    {
        try {
            $user = Auth::user();

            return ErrorReport::create([
                'reference' => ErrorReport::generateReference(),
                'company_id' => $this->tenantContext->companyId() ?? $user?->company_id,
                'user_id' => $user?->getKey(),
                'exception_class' => get_class($e),
                'message' => Str::limit((string) $e->getMessage(), 60000, ''),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'status_code' => $statusCode,
                'method' => $request?->method(),
                'url' => $request?->fullUrl(),
                'ip_address' => $request?->ip(),
                'user_agent' => Str::limit((string) $request?->userAgent(), 255, ''),
                'trace' => $e->getTraceAsString(),
                'context' => $this->context($request),
            ]);
        } catch (Throwable $captureFailure) {
            // Never let error capture mask the original failure. Fall back to
            // the log channel so the incident (and the capture failure) survive.
            report($captureFailure);

            return null;
        }
    }

    /**
     * A small, PII-minimised context payload: the matched route name and the
     * request input KEYS only (never their values, which may carry personal or
     * payment data). Kept deliberately thin — the trace/url/user already carry
     * the bulk of the diagnostic signal.
     *
     * @return array<string, mixed>
     */
    private function context(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        return [
            'route' => $request->route()?->getName(),
            'input_keys' => array_values(array_slice(array_keys($request->except([
                'password', 'password_confirmation', 'current_password', '_token',
            ])), 0, 50)),
            'is_ajax' => $request->ajax(),
            'referer' => Str::limit((string) $request->headers->get('referer'), 500, ''),
        ];
    }
}
