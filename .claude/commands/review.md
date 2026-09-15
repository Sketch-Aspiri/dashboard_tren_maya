---
description: Review code that was just written or modified in the Dashboard Jefe de Zona, using the project's code-reviewer agent (and security-auditor when the change touches sensitive areas).
argument-hint: [optional: file path or feature name — leave empty to review the current uncommitted changes]
---

# Review

Code was just written or modified. Review it before it's considered done — this is mandatory for every change, no matter how small (see `code-review.md`: review is required after writing or modifying code, and before any commit).

Target: $ARGUMENTS

Use TodoWrite to track the phases of this command.

---

## Phase 1: Scope the Review

**Goal**: Know exactly what to review before launching any agent.

**Actions**:
1. If `$ARGUMENTS` is empty, scope the review to what actually changed:
   - If this is a git repository, run `git status` and `git diff` (staged and unstaged) to see everything that changed.
   - If this is not a git repository (check first — `git status` will say so), find the files modified in this session from conversation context, or ask the user which files to review.
2. If `$ARGUMENTS` names a file, path, or feature, scope the review to that instead of the full diff.
3. Read the full content of every file in scope — don't review a diff hunk in isolation if the surrounding function/class matters for correctness.

---

## Phase 2: Code Quality Review

**Goal**: Catch quality, convention, and correctness issues.

**Actions**:
1. Launch the `code-reviewer` agent with the scoped files/diff as context.
2. While waiting (or if the agent is unavailable), apply the project's own checklist directly: readable naming, functions under 50 lines, files under 800 lines, nesting under 4 levels, explicit error handling, no hardcoded secrets, no `dd()`/`dump()`/`console.log` left behind, Form Requests for validation, Policies for authorization, `activitylog` on mutations, no raw SQL with concatenated input, no `{!! !!}` with user input, tests exist for new behavior.

---

## Phase 3: Security Review (conditional)

**Goal**: Don't let a security-sensitive change slip through on a quality review alone.

**Actions**:
1. Check whether the reviewed change touches: authentication, 2FA, roles/permissions, user input handling, file uploads, the CRUD module, database queries, or server/Nginx/MySQL configuration.
2. If yes, launch the `security-auditor` agent on the same scope — run it alongside `code-reviewer`, not instead of it.
3. If the change is purely visual (Blade/Tailwind styling with no new input or data flow), it's fine to skip this phase — say so explicitly rather than silently omitting it.

---

## Phase 4: Consolidate Findings

**Goal**: Give the user one clear picture, not two separate agent dumps.

**Actions**:
1. Merge findings from `code-reviewer` and `security-auditor` (when run), de-duplicating anything both flagged.
2. Sort by severity: CRITICAL, then HIGH, then MEDIUM, then LOW.
3. For each finding, present: `[SEVERITY] file:line — issue — fix`.

---

## Phase 5: Verdict and Decision

**Goal**: Apply the project's approval criteria, don't just list issues and stop.

**Actions**:
1. Apply the approval criteria from `code-review.md`:
   - **Approve** — no CRITICAL or HIGH issues.
   - **Warning** — only HIGH issues (mergeable with caution).
   - **Block** — any CRITICAL issue found.
2. State the verdict plainly.
3. If there are CRITICAL or HIGH findings, ask the user what they want to do: fix now, fix later, or proceed as-is. Don't silently proceed past a CRITICAL finding.

---

## Phase 6: Fix (if requested)

**Actions**:
1. If the user wants fixes applied now, delegate them to the `code-writter` agent (or apply directly if trivial), one finding at a time for anything non-trivial.
2. After fixing, re-run the relevant tests (`php artisan test`) to confirm nothing broke.
3. Re-run this review on the changed portion if the fix was non-trivial, rather than assuming it's clean.

---

## Phase 7: Summary

Report concisely:
- **Scope reviewed**: files/diff covered.
- **Verdict**: Approve / Warning / Block.
- **Findings**: resolved vs. still open, with severity.
- **Follow-ups**: anything left for the user to decide (e.g., a HIGH issue they chose to defer).

Do not commit anything as part of this command unless the user explicitly asks for it.
