---
description: Create a safe new migration for a schema change
argument-hint: <describe the schema change>
---

Change: $ARGUMENTS

1. Inspect the existing migration(s) and model for the table. Never alter an existing migration.
2. Create ONE new migration for this logical change (`php artisan make:migration`).
3. Rules: `foreignId()->constrained()`, explicit `->nullable()` for optional columns, `->comment()` on ambiguous columns, indexes for lookup columns, a correct `down()`.
4. Consider existing data: defaults/backfill for new NOT NULL columns.
5. Update the model `$fillable`/`$casts` and any FormRequest/Resource affected.
6. Do NOT run `migrate` without confirming with the user. Never delete a table or column without asking.
