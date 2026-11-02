<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CustomerApiController;
use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\Api\ScanApiController;
use App\Http\Controllers\Api\SessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Routes for the native iOS scanner app's JSON API. Every route here runs
| through the `api` middleware group (which prepends `EnforceHttps`), and the
| authenticated, tenant-scoped routes additionally run through `auth:sanctum`
| and the `api.tenant` group. Endpoints are added by later tasks.
|
*/

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| `login` and `two-factor` are public (no token exists yet) and carry the
| anti-guessing rate limiters keyed per email+IP. `logout` and `me` require a
| valid, non-expired Sanctum token; `me` additionally runs under `api.tenant`
| so the Active_Company is bound for any tenant-scoped reads. (Requirements
| 1.x, 2.x, 3.1, 3.3, 15.1, 15.3)
|
*/
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::post('/auth/two-factor', [AuthController::class, 'twoFactor'])
    ->middleware('throttle:2fa');

Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

Route::get('/auth/me', [AuthController::class, 'me'])
    ->middleware(['auth:sanctum', 'api.tenant']);

/*
|--------------------------------------------------------------------------
| Company selection
|--------------------------------------------------------------------------
|
| Both routes require a valid, non-expired Sanctum token but deliberately run
| WITHOUT `api.tenant`: a multi-membership user signs in with a token that has
| no Active_Company yet, so listing memberships and selecting one must not
| require a tenant to already be bound. `select` is the act that sets the
| token's Active_Company and re-mints its `company:{id}` ability; later
| tenant-scoped requests then resolve the chosen Company via `ResolveApiTenant`.
| (Requirements 4.2, 4.3, 4.4, 4.5, 4.6, 16.3)
|
*/
Route::get('/companies', [CompanyController::class, 'index'])
    ->middleware('auth:sanctum');

Route::post('/companies/{id}/select', [CompanyController::class, 'select'])
    ->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Scanning
|--------------------------------------------------------------------------
|
| The check-in endpoint. Requires a valid, non-expired Sanctum token and runs
| under `api.tenant` so the Active_Company is bound and every Order lookup is
| tenant-scoped. The per-operator `throttle:scan` limiter (keyed on the
| authenticated operator's id) caps one operator's scan volume without ever
| throttling another operator working the same door. The controller gates on
| `ACTION_CHECK_IN` before resolving anything. (Requirements 5.1–5.10, 6.1,
| 6.2, 15.2)
|
*/
Route::post('/scan', [ScanApiController::class, 'scan'])
    ->middleware(['auth:sanctum', 'api.tenant', 'throttle:scan']);

/*
|--------------------------------------------------------------------------
| Live dashboard
|--------------------------------------------------------------------------
|
| The door operator's live snapshot for the Active_Event. Requires a valid,
| non-expired Sanctum token and runs under `api.tenant` so the Active_Company
| is bound and the Active_Event is resolved tenant-scoped. The controller gates
| on `ACTION_CHECK_IN` (the API dashboard is a door tool, so the Scanner role
| may see it — the one deliberate authorisation difference from the web
| dashboard's `ACTION_VIEW_REPORTS`). The payload carries attendance counts and
| the recent scan feed only, never sales/revenue figures. (Requirements
| 7.1–7.5)
|
*/
Route::get('/dashboard/live', [DashboardApiController::class, 'live'])
    ->middleware(['auth:sanctum', 'api.tenant']);

/*
|--------------------------------------------------------------------------
| Customer search
|--------------------------------------------------------------------------
|
| Tenant-scoped Customer search. Requires a valid, non-expired Sanctum token
| and runs under `api.tenant` so the Active_Company is bound and the aggregate
| query is confined to the acting Company (no cross-company rows leak). The
| controller gates on `ACTION_MANAGE_ORDERS` before reading anything.
| (Requirements 8.1, 8.2, 8.3, 12.3)
|
*/
Route::get('/customers', [CustomerApiController::class, 'index'])
    ->middleware(['auth:sanctum', 'api.tenant']);

/*
|--------------------------------------------------------------------------
| Sessions (self)
|--------------------------------------------------------------------------
|
| A Staff_User's own active Sessions. Both routes require a valid, non-expired
| Sanctum token but run WITHOUT `api.tenant`: a user manages their own Sessions
| independently of any Active_Company. Listing exposes each token's id, device
| label, last-used instant and a `current` flag; revoking deletes only the
| caller's own token (a foreign/unknown id → 404, never visible), after which
| `auth:sanctum` rejects any later request bearing it. (Requirements 14.1,
| 14.2, 14.7, 16.2)
|
*/
Route::get('/sessions', [SessionController::class, 'index'])
    ->middleware('auth:sanctum');

Route::delete('/sessions/{id}', [SessionController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->whereNumber('id');

/*
|--------------------------------------------------------------------------
| Sessions (team / super-admin)
|--------------------------------------------------------------------------
|
| Another member's Sessions, gated on the `users` token ability. These run
| under `api.tenant` so the Active_Company is bound: the controller re-checks
| `Gate::authorize(ACTION_MANAGE_USERS)` (a Super_Admin passes cross-company via
| the `Gate::before` bypass) and, for a non-super-admin actor, requires the
| target `{user}` to be an Active_Company member via `company_user` — a
| non-privileged actor or non-member target → 403 with tokens unchanged.
| Binding the tenant here also gives the `TOKEN_REVOKED` Audit_Entry its
| `company_id`. (Requirements 14.3, 14.4, 14.5, 14.6, 14.7, 16.2)
|
*/
Route::get('/users/{user}/sessions', [SessionController::class, 'teamIndex'])
    ->middleware(['auth:sanctum', 'api.tenant', 'ability:users']);

Route::delete('/users/{user}/sessions/{id}', [SessionController::class, 'teamDestroy'])
    ->middleware(['auth:sanctum', 'api.tenant', 'ability:users'])
    ->whereNumber('id');
