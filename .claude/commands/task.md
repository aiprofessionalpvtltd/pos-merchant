---
description: Implement a Laravel task end-to-end following project rules
argument-hint: <task description>
---

Task: $ARGUMENTS

Follow CLAUDE.md strictly:

1. Read only the relevant files (routes, controller, model, migration, view, request, service). Do not scan the whole project.
2. Give a short **implementation plan** first (files to change/create, migrations, routes, permissions). Wait for confirmation if the change is large or touches the DB schema.
3. Implement the smallest change that satisfies the task. Extend existing logic; do not rewrite working code.
   - Thin controllers -> FormRequest validation -> Service -> Resource/JSON.
   - `$fillable` on models, eager loading, `DB::transaction` for multi-step writes.
   - New migration for schema changes; never edit existing ones.
   - Named routes; permission middleware / `authorize()`.
4. Run `./vendor/bin/pint` on changed files and any relevant tests (`php artisan test --filter=...`).
5. Finish with a list of every **changed/created file**, and update `docs/` if an API endpoint changed.
