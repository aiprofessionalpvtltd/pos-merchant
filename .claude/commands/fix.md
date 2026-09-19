---
description: Debug and fix a bug with root-cause analysis
argument-hint: <bug description or error message>
---

Bug: $ARGUMENTS

1. Reproduce or locate it: check `storage/logs/laravel.log`, the route, controller, service and model involved.
2. Find the **root cause** before changing anything; state it in one or two sentences with `file:line`.
3. Apply the smallest fix. Do not refactor unrelated code.
4. Add or update a test that fails without the fix and passes with it.
5. Run the relevant tests and `./vendor/bin/pint` on changed files.
6. Report: root cause, fix, files changed, how it was verified.
