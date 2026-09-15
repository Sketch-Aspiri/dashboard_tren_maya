# API Conventions — Dashboard Jefe de Zona

Project-specific conventions for JSON/AJAX endpoints in the Tren Maya
"Dashboard Jefe de Zona" (Laravel 11). Enforced by `code-writter` and
`code-reviewer`.

## Scope & Philosophy

This is a **server-rendered Blade app**, not a SPA or a public API product.
"API" here means the internal JSON endpoints that back Alpine.js
interactions — charts (fetch data for Chart.js/ApexCharts), the advanced
search/filter UI, and any AJAX pagination/filtering on tables. There is no
public-facing API in v1, and the system is VPN-only — do not design
endpoints as if they'll be hit by untrusted third-party clients.

- Auth is **session-based** (Breeze/Fortify + 2FA), not token-based. Do not
  reach for Sanctum API tokens or bearer auth for internal endpoints — that
  belongs to a future phase (see "Future Public API" below), not today's
  Alpine fetch calls.
- Every JSON endpoint follows the **same auth/authorization/audit rules**
  as a web route. "It's just for a chart" is not an exemption.

## Route Organization

- Internal JSON endpoints live in `routes/web.php` (or a `routes/*.php`
  file included from it), under the `web` middleware group — they need the
  session and CSRF protection, not the `api` group's stateless posture.
- Group related endpoints with a route prefix matching the feature, not a
  version number: `Route::prefix('dashboard')->group(...)`,
  `Route::prefix('examples')->group(...)`.
- Do not create `routes/api.php` / `/api/*` routes until an actual external
  consumer (mobile app, integration, future multi-user public API) exists.
  Until then, a `routes/api.php` entry is scope creep, not preparation.
- Name routes resourcefully and consistently with the web CRUD routes they
  support: `examples.index`, `examples.data` (JSON variant for a table's
  AJAX refresh), `dashboard.kpis`, `dashboard.chart-data`.

## Auth & CSRF for Fetch/Alpine Calls

- Every `fetch()`/Alpine `x-data` call to an internal endpoint sends the
  CSRF token from the `<meta name="csrf-token">` tag in the layout head,
  via the `X-CSRF-TOKEN` header — never disable CSRF for convenience.
- Use `credentials: 'same-origin'` (the default) — never build a
  cross-origin fetch pattern into this app; there is nothing to be
  cross-origin with.
- An unauthenticated or 2FA-incomplete request to a JSON endpoint returns
  `401`/`403` with a JSON body (see envelope below), not a redirect to the
  login page — the frontend JS can't follow an HTML redirect usefully.

## Authorization

- Same rule as web controllers: `$this->authorize(...)` against a Policy
  backed by `spatie/laravel-permission`, before returning any data. A
  chart-data endpoint that skips authorization because "it's read-only" is
  a CRITICAL finding, not a style nit.
- Scope queries to what the authenticated user/role may see — don't rely
  on the frontend to hide rows it already received.

## Response Envelope

Use a consistent JSON shape for every internal endpoint response:

```json
{
  "success": true,
  "data": { },
  "error": null,
  "meta": null
}
```

- `data`: the payload — a Laravel API Resource (`JsonResource`) or
  resource collection, never a raw Eloquent model/array dump (keeps
  `$hidden` respected and response shape explicit).
- `error`: `null` on success; on failure, a short machine-usable code plus
  a human-readable message (see Error Format).
- `meta`: pagination info (see below) or `null` when not applicable.

```php
return response()->json([
    'success' => true,
    'data' => ExampleResource::collection($examples),
    'error' => null,
    'meta' => [
        'total' => $examples->total(),
        'page' => $examples->currentPage(),
        'per_page' => $examples->perPage(),
        'last_page' => $examples->lastPage(),
    ],
]);
```

## Error Format

- Validation failures: let the Form Request's automatic `422` response
  through (Laravel's standard `{"message": "...", "errors": {...}}`
  shape) — don't hand-roll a different validation error format for JSON
  endpoints just because they're not a full-page submit.
- Authorization failures: `403` with
  `{"success": false, "data": null, "error": {"code": "forbidden", "message": "..."}, "meta": null}`.
- Not found: `404` with the same envelope, `error.code = "not_found"`.
- Server errors: never leak stack traces, SQL, or file paths in the
  response body — log the detail server-side, return a generic message.

## Status Codes

- `200` — successful read.
- `201` — resource created (JSON endpoints that create something, e.g. a
  saved filter/report request).
- `204` — successful action with no body to return.
- `401` — not authenticated / 2FA not completed.
- `403` — authenticated but not authorized (Policy denial).
- `404` — resource doesn't exist or isn't visible to this user (don't leak
  existence of records outside the user's scope by returning `403` instead
  of `404` when appropriate).
- `422` — validation failure.
- `429` — rate limited.

## Pagination & Filtering

- Use Laravel's built-in `paginate()`/`simplePaginate()` — never fetch a
  full table into memory to paginate manually.
- Filter/sort/date-range params are plain query strings with consistent
  names across endpoints: `from`, `to`, `sort`, `direction`, `q` (search
  term), `page`, `per_page`. Don't invent a new name for the same concept
  per endpoint (e.g. `start_date` in one place and `from` in another).
- Validate filter/query params through a Form Request (`GET` requests can
  still use `FormRequest::rules()` on query data) — don't trust raw
  `$request->query(...)` values into a query builder unvalidated.

## Rate Limiting

- Any JSON endpoint that's expensive (report generation, broad search) or
  auth-adjacent gets a `RateLimiter::for(...)` definition, same as web
  routes. Search-as-you-type endpoints should be debounced client-side
  *and* rate-limited server-side — don't rely on frontend debounce alone.

## Audit Logging

- JSON endpoints that create/update/delete data go through
  `spatie/laravel-activitylog` exactly like their web counterparts. A
  "quick AJAX action" is not exempt from the audit trail requirement in
  `CLAUDE.md`.

## Exports (PDF/Excel)

- Report/export endpoints (blocked on final report format per `CLAUDE.md`
  — build against dummy data until confirmed) return a file download
  (`response()->download(...)` / streamed response), not the JSON
  envelope above — but still go through the same authorization and audit
  logging as any other data-exposing endpoint.
- Never build an export endpoint that accepts an arbitrary file path or
  template name from client input — export type/format is an enum
  validated server-side, not a free-form string.

## Future Public API

If/when this system grows beyond one user and needs a genuine external API
(mobile client, integration partner), that's a deliberate new phase, not an
extension of today's internal endpoints:

- It gets its own `routes/api.php`, `/api/v1/...` prefix, and real
  versioning from day one.
- Token auth via Laravel Sanctum (already a natural fit given
  `spatie/laravel-permission` is in place), with abilities scoped per
  token.
- The response envelope and error format defined here carry over — don't
  redesign the shape, just add versioning and token auth around it.
- Do not start building this speculatively — YAGNI applies; today's
  internal AJAX endpoints don't need it.
