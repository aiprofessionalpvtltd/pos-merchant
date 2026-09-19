---
description: Produce an implementation plan only (no code changes)
argument-hint: <feature or change>
---

Feature/change: $ARGUMENTS

Do NOT write or edit any code. Read only the relevant routes, controllers, models, migrations, services and existing `docs/*.md`.

Output a plan with these sections:

1. **Goal** - one or two sentences.
2. **Current state** - what exists today (with `file:line` references).
3. **Changes** - table of file | action (create/modify) | what changes. Cover: migration, model, FormRequest, Service, Controller, Resource, route, permission/seeder, tests, docs.
4. **API contract** - method, path, auth, request, response, error codes (if an endpoint is involved).
5. **Risks / edge cases** - data migration, backward compatibility, permissions, N+1, transactions.
6. **Open questions** - anything needing a user decision.
7. **Order of work** - numbered steps.

Save nothing unless asked; end by asking for approval.
