---
description: Write and run PHPUnit feature tests for a route or class
argument-hint: <route, controller or service>
---

Target: $ARGUMENTS

1. Read the target and any existing tests in `tests/` for style and helpers.
2. Write Feature tests (Unit tests for Services) covering: success, validation errors (422), unauthenticated (401), unauthorized (403), not found (404), and key edge cases. Use factories and `RefreshDatabase`.
3. Run with `php artisan test --filter=<Name>` and fix failures caused by the tests themselves.
4. If a test reveals a real bug, report it instead of silently changing behavior.
5. List created/changed test files and the result.
