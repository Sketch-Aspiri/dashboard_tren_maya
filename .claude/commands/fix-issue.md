---
description: Diagnose and fix a bug in the Dashboard Jefe de Zona following TDD, using the project's agent pipeline (code-writter → code-reviewer → security-auditor when applicable).
argument-hint: [bug description, error message, or reproduction steps]
---

# Fix Issue

A bug has been reported. Your job is to find the root cause and fix it — not silence the symptom. Follow the phases in order; do not skip the reproduction phase or the test phase.

Issue report: $ARGUMENTS

Use TodoWrite to track the phases of this command.

---

## Phase 1: Understand the Report

**Goal**: Know exactly what's failing before touching any code.

**Actions**:
1. If `$ARGUMENTS` doesn't include clear reproduction steps, the affected screen/endpoint, or the full error message, ask the user before proceeding. Don't guess at the bug.
2. If there's a stack trace or error message, extract the file and line it points to.
3. Check `storage/logs/laravel.log` (`tail`/`Read`) if the report suggests a server-side error with no visible stack trace in the chat.

---

## Phase 2: Reproduce and Locate the Root Cause

**Goal**: Confirm the bug in the code, not just in the description.

**Actions**:
1. Locate the file(s) involved with `Grep`/`Glob` (route, controller, model, Blade view, Form Request, Policy, as applicable).
2. Read the full affected path: route → middleware → controller → Form Request/Policy → model/service → view. Don't patch the first symptom you see without understanding why it happens.
3. If the bug involves data, check whether it depends on fields that `CLAUDE.md` marks as **blocked on external information** (final data model, KPIs, charts, reports). If so, say so explicitly — it may not be a bug but an expected gap while dummy data is in use — don't invent the real data model to "fix" it.
4. If existing tests cover this area, run them first: `php artisan test --filter=...` to confirm the current state.

---

## Phase 3: Write a Test That Reproduces the Bug (RED)

**Goal**: Before fixing anything, have a test that fails for exactly this reason (project rule: TDD is mandatory, see `testing.md`).

**Actions**:
1. Write (or extend) a test — unit or integration, as appropriate — that reproduces the incorrect behavior.
2. Run the test and confirm it **fails** for the expected reason, not because of an unrelated issue (broken fixture, missing route, etc.).
3. If the bug is purely visual/UX and not reasonably testable, explicitly document why the automated test is being skipped instead of silently skipping it.

---

## Phase 4: Fix

**Goal**: Apply the minimal, correct fix while respecting the project's conventions.

**Actions**:
1. Launch the `code-writter` agent to implement the fix — give it the file, the identified root cause, and the failing test as context. If the fix is trivial (a one-liner, no security or architectural implications), you may apply it directly instead of delegating.
2. The fix must respect the project's non-negotiable checklist: validation in a Form Request, authorization via a Policy/Spatie role, `activitylog` on mutations, no raw SQL with concatenated input, no `{!! !!}` with user input.
3. Don't fix unrelated code "while you're in there" — keep the diff focused on this bug.

---

## Phase 5: Verify

**Goal**: Confirm the fix works and doesn't break anything else.

**Actions**:
1. Run the Phase 3 test — it must now pass (GREEN).
2. Run the relevant full suite: `php artisan test`.
3. Run `./vendor/bin/pint --test` if configured.
4. If the bug was visible in the UI, confirm in the browser (or ask the user to confirm) that it no longer occurs.

---

## Phase 6: Review

**Goal**: Don't consider the bug closed without going through the project's quality/security pipeline.

**Actions**:
1. Launch the `code-reviewer` agent on the fix's diff — mandatory for any change, no matter how small.
2. If the bug touched authentication, 2FA, roles/permissions, user input, file uploads, the CRUD module, or server/Nginx/MySQL configuration, also launch `security-auditor` before considering this resolved.
3. Resolve any CRITICAL or HIGH finding before reporting the bug as fixed.

---

## Phase 7: Summary

Report concisely:
- **Root cause**: what was wrong and why (not just what changed).
- **Fix applied**: files touched, with `file:line` references.
- **Test added/updated**: which one, and confirmation it now passes.
- **Review findings**: anything `code-reviewer`/`security-auditor` flagged, and how it was resolved.
- **Related pending work**: if the real root cause depends on still-blocked information (data model, KPIs, etc.), say so here instead of leaving it implicit.

Do not commit the fix unless the user explicitly asks for it.
