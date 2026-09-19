---
description: Review current changes against project rules
argument-hint: [optional path or branch]
---

Review the uncommitted changes (`git diff` and `git status`), or `$ARGUMENTS` if given.

Check against CLAUDE.md:
- Thin controllers; logic in Services; no DB queries in controllers
- FormRequest on every store/update; `authorize()` present
- `$fillable` set, no `$guarded = []`; eager loading, no N+1; transactions for multi-step writes
- Permissions/policies enforced; sensitive actions logged
- API returns Resources + consistent JSON envelope; lists paginated
- New migrations only (no edits to old ones); routes named
- No `dd`/`dump`/`var_dump`, no commented-out code
- Security: input validation, mass assignment, authorization gaps, SQL injection, leaked secrets
- Tests and `docs/` updated where needed

Output findings grouped by severity (Blocker / Should fix / Nit) with `file:line` and a suggested fix. Do not edit files.
