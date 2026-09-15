# Code Style Rules — Dashboard Jefe de Zona

Project-specific code style for the Tren Maya "Dashboard Jefe de Zona"
(Laravel 11 / PHP 8.3, Blade + Tailwind + Alpine.js, MySQL 8). These extend
the global coding-style rules and are enforced by the `code-writter` and
`code-reviewer` agents in this repo.

## Standard

- PSR-12, checked with `./vendor/bin/pint --test` (Laravel's bundled Pint).
  Run `./vendor/bin/pint` before considering any PHP change done.
- Generate scaffolding with `php artisan make:*` (`model -mcr`, `request`,
  `policy`, `migration`) instead of hand-writing boilerplate — keeps
  namespaces, imports, and stubs consistent with Laravel conventions.

## Layering (non-negotiable)

- **Controllers stay thin**: orchestrate only — authorize, delegate to a
  Service/Action class, return a response. If a method has more than a
  couple of lines of business logic, extract it.
- **Validation** lives only in `app/Http/Requests` Form Requests. Never
  `$request->validate()` inline in a controller, never feed
  `$request->all()` to `create()`/`update()` — use `->validated()` or
  `->safe()`.
- **Authorization** lives only in `app/Policies`, backed by
  `spatie/laravel-permission` roles. Controllers call
  `$this->authorize(...)` / `Gate::authorize(...)` — never an inline
  `if ($user->role === ...)` check.
- **Audit logging**: every create/update/delete goes through
  `spatie/laravel-activitylog` (`LogsActivity` trait or an explicit log
  call) — this is a style requirement, not optional polish.

```php
// Controller — thin: authorize, delegate, respond
final class ExampleController extends Controller
{
    public function __construct(private readonly ExampleService $service) {}

    public function store(StoreExampleRequest $request): RedirectResponse
    {
        $this->authorize('create', Example::class);

        $this->service->create($request->validated());

        return redirect()->route('examples.index');
    }
}
```

## Models

- Explicit `$fillable` — never `$guarded = []`.
- Explicit `$casts` for dates, enums, booleans, JSON columns.
- Sensitive fields (tokens, 2FA secrets) in `$hidden`.
- Eloquent/Query Builder only. No raw string interpolation in
  `whereRaw`/`DB::raw`/`DB::statement`/`orderByRaw` — parameterize.
- Eager-load relationships used in views/serialization (`with()`/`load()`)
  instead of letting N+1 queries happen implicitly.

## Migrations

- Timestamped filename, anonymous class (Laravel 11 default).
- `down()` always implemented, even though rollback is rare in practice.
- Foreign keys declare an explicit `on delete` behavior — no implicit
  default.

## Naming

- Classes/Enums/Policies/Requests: `PascalCase`
  (`StoreExampleRequest`, `ExamplePolicy`).
- Variables/methods: `camelCase`, descriptive — no single-letter names
  outside tight loop counters.
- Booleans: `is`/`has`/`should`/`can` prefix (`isBlockedField`,
  `canDelete`).
- Routes: `kebab-case` URIs, resourceful naming (`examples.index`,
  `examples.store`) — reuse the generic CRUD module's route naming
  pattern rather than inventing a new one per feature.
- Blade partials/components: `kebab-case` filenames matching the
  component tag (`x-kpi-card` → `kpi-card.blade.php`).

## Blade / Alpine

- `{{ }}` escaping by default. `{!! !!}` only for markup generated
  server-side by trusted code — never for user input or anything derived
  from it.
- Extract to `resources/views/components` anything reused more than
  twice (KPI cards, table shells, layout chrome) instead of copy-pasting
  markup across views.
- Alpine.js only for genuine client-side interaction (toggle, filter,
  modal state) — don't reach for it to duplicate state Blade already
  renders server-side.
- Mixed PHP/HTML in views: keep logic to conditionals/loops; push
  anything heavier into the controller/service/view composer.

## File & Function Shape

- Functions/methods: under 50 lines — extract early returns/guard
  clauses instead of nesting past 4 levels.
- Files: under 800 lines — split a fat controller/service by feature
  boundary, not by arbitrary line count alone.
- One class per file, filename matches class name (PSR-4 autoloading
  already enforces this — don't fight it with multi-class files).

## Forbidden in Committed Code

- `dd()`, `dump()`, `var_dump()`, or any other debug output.
- Hardcoded secrets, API keys, tokens, credentials — `.env` only, never
  committed.
- `$guarded = []` on any model.
- Bare `catch (\Exception $e) {}` — log and handle, never swallow
  silently.
- New routes/actions without a backing Policy, even for the single
  current user.

## Reference

Follow the `laravel-patterns` and `laravel-security` skills for deeper
examples (query objects, transactions, Sanctum abilities, CSP headers) —
this file covers this project's specific conventions, not Laravel's full
surface area. The generic CRUD module under active development is meant
to be adapted, not forked, once the real data model lands — keep it
generic enough that swapping field names doesn't require restructuring
the controller/policy/request layer.
