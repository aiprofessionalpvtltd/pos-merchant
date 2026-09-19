---
description: Write or update API documentation in docs/ for an endpoint or module
argument-hint: <module or route, e.g. cart, GET /orders>
---

Target: $ARGUMENTS

1. Read the routes (`routes/api.php`), the controller, FormRequest, Resource and Service for the target. Read the matching `docs/<module>.md` if it exists, and `docs/errors.md` / `docs/README.md` for shared conventions.
2. Write or update `docs/<module>.md` in the exact style of the existing docs:
   - `# Title`, short intro paragraph.
   - Summary table: Method | Path | Auth (with anchor links).
   - Per endpoint: `## METHOD /path`, description, **Query** / **Body** parameter tables (Param | Type | Required/Default | Notes), **Response `200`** JSON example, and error responses (`401`, `403`, `404`, `422`...) referencing `docs/errors.md`.
   - Response envelope: `{ "success": true, "data": ..., "message": ... }`.
3. Document only what the code actually does. Verify field names, validation rules and status codes against the FormRequest and Resource - do not invent.
4. If a new module doc is created, add it to `docs/README.md`.
5. Report which doc files were changed.
