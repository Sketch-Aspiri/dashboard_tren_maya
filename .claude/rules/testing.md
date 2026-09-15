# Testing Rules — Dashboard Jefe de Zona

Project-specific testing rules for the Tren Maya "Dashboard Jefe de Zona"
(Laravel 11 / PHP 8.3). These extend the global testing standards and are
enforced by the `code-writter`, `code-reviewer`, and `security-auditor`
agents in this repo.

## Framework

- Test runner: PHPUnit (Laravel default) via `php artisan test`. Pest is
  acceptable if introduced consistently project-wide — do not mix styles
  file by file.
- Test types live under `tests/Unit`, `tests/Feature`. Use `Feature` tests
  for anything touching HTTP, auth, database, or policies — `Unit` only for
  pure logic (services, helpers, casts) with no framework bootstrapping.
- Use `RefreshDatabase` (or `DatabaseTransactions`) on every Feature test
  that touches the database. Never let tests write to a real/dev database —
  configure a dedicated `testing` connection (SQLite in-memory or a
  disposable MySQL schema) in `phpunit.xml`.

## TDD Workflow (mandatory)

1. **RED** — write a failing test for the behavior before implementation.
2. **GREEN** — write the minimal code to pass it.
3. **REFACTOR** — clean up with the test suite green.
4. Run `php artisan test` (or the relevant `--filter`) after every step;
   never hand off code that hasn't been run.

New code ships with **80%+ coverage**. If coverage tooling
(`php artisan test --coverage`, Xdebug/PCOV) isn't configured yet, say so
explicitly rather than claiming the number.

## What Must Be Tested (non-negotiable, per module)

Every CRUD module / feature (including the generic reference CRUD) needs
Feature tests covering:

- [ ] **Authorization** — each Policy ability (`view`, `create`, `update`,
      `delete`) denies a user without the role/permission (expect `403`)
      and allows one with it. Test this per Spatie role, not just "logged
      in vs. not."
- [ ] **Authentication** — protected routes redirect/`401` when
      unauthenticated; 2FA-gated routes cannot be reached by a user who
      hasn't completed the `google2fa-laravel` challenge.
- [ ] **Validation** — each Form Request: required fields missing → fails;
      valid payload → passes; boundary cases (max length, type mismatch)
      are rejected. Assert on `assertInvalid()`/`assertValid()`, not just
      status codes.
- [ ] **Mass-assignment safety** — a request with extra/unexpected fields
      does not persist attributes outside `$fillable`.
- [ ] **Audit logging** — create/update/delete on a model produces an
      `activitylog` entry (assert against the `Spatie\Activitylog\Models\Activity`
      table: causer, event, subject).
- [ ] **Happy path** — the full create → read → update → delete cycle for
      the resource, asserting persisted state, not just HTTP status.
- [ ] **Query scoping** — if/when multi-user scoping exists, a user cannot
      read/edit/delete another user's or zone's records (write this test
      as soon as any ownership/scope column is introduced).

## Security-Sensitive Areas — Extra Coverage

Anything touching auth, 2FA, roles, or user input gets tests, not just a
review pass:

- Login: correct credentials + 2FA succeeds; wrong password fails; wrong/expired
  2FA code fails; session is regenerated on login and invalidated on logout.
- Rate limiting: exceeding `RateLimiter::for(...)` thresholds on
  login/password-reset returns `429`.
- File uploads (when introduced): reject disallowed MIME types/extensions
  and oversized files; never trust the client-supplied extension alone.
- No test may assert on or log real secrets, tokens, or `.env` values —
  use factories/fakes for anything credential-shaped.

## Test Structure (AAA)

```php
public function test_zone_chief_can_update_own_record(): void
{
    // Arrange
    $user = User::factory()->zoneChief()->create();
    $record = Example::factory()->create();

    // Act
    $response = $this->actingAs($user)
        ->put(route('examples.update', $record), ['name' => 'Updated']);

    // Assert
    $response->assertRedirect(route('examples.index'));
    $this->assertDatabaseHas('examples', ['id' => $record->id, 'name' => 'Updated']);
}
```

Name tests as `test_{actor}_{action}_{expected_outcome}` (or the
Pest-equivalent `it(...)` description) — the behavior must be readable from
the name alone, e.g. `test_guest_cannot_access_dashboard`,
`test_admin_can_delete_example_and_it_is_logged`.

## Dummy Data (while the real data model is pending)

Per `CLAUDE.md`, the final CRUD data model, KPI definitions, chart types,
and report formats are **blocked** on information from the Jefe de Zona.
Until that lands:

- Build tests against **factories with dummy/seeded data**, structured so
  swapping in the real fields later only touches the factory/migration, not
  the test intent (authorization, validation, audit logging stay valid
  regardless of the final field names).
- Do not invent "real" business fields to make a test look complete — keep
  dummy fields obviously generic (`name`, `value`, `status`) until the real
  model is confirmed.

## Running Tests

```bash
php artisan test                          # Full suite
php artisan test --filter=ExampleTest      # Single test class
php artisan test --coverage                # Coverage report (needs Xdebug/PCOV)
./vendor/bin/pint --test                   # Formatting check (run alongside tests)
```

## Failure Triage

- Fix the implementation, not the test — unless the test itself is wrong
  (asserts the wrong behavior), which should be called out explicitly, not
  silently "fixed" to match broken code.
- Check test isolation first for flaky failures (missing
  `RefreshDatabase`, shared state between tests, un-frozen `now()`).
- Verify mocks/fakes (e.g. `Notification::fake()`, `Storage::fake()`) match
  what the code under test actually calls.

## Before Marking a Feature Done

- [ ] `php artisan test` passes locally.
- [ ] Every checklist item under "What Must Be Tested" applies and is
      covered, or is explicitly marked N/A with a reason.
- [ ] Coverage on new/changed code is 80%+, or the gap is stated plainly.
- [ ] Handed off to `code-reviewer`, and to `security-auditor` for anything
      touching auth, 2FA, roles, user input, file uploads, or the CRUD
      module.
