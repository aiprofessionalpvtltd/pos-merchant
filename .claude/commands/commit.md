---
description: Run pint and create a conventional commit
argument-hint: [optional message hint]
---

1. Run `git status` and `git diff` to see the changes.
2. Run `./vendor/bin/pint` on the changed PHP files.
3. Stage only the relevant files (never `.env`, secrets, or vendor).
4. Commit with a conventional message: `feat: ...`, `fix: ...`, `refactor: ...`, `docs: ...`. One logical change per commit. Hint: $ARGUMENTS
5. Do not push unless asked.
